<?php

namespace App\Assistant;

use App\Assistant\Budget\AssistantBudget;
use App\Assistant\Model\AssistantModel;
use App\Assistant\Model\ModelRef;
use App\Assistant\Model\ModelRequest;
use App\Assistant\Model\ModelTurn;
use App\Assistant\Model\ModelUnavailable;
use App\Assistant\Tools\AnnouncementDraft;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Answers one question of the signed-in account.
 *
 * The conversation is frozen with its plan (App\Assistant\ConversationPlan:
 * model, effort, static system prompt, tools) and its transcript is
 * append-only: the question goes at the end, with the context block in
 * the user turn, and every model turn is kept exactly as returned. While
 * the model asks for tools, they run for that account and their results go
 * back, at most {@see AssistantSettings::$maxToolRounds} rounds.
 *
 * What is kept when a question ends:
 * - an answer: the question and all its turns;
 * - an answer cut by max_tokens, or a tool loop that does not stop: the
 *   complete turns, then the message for the page as a plain assistant
 *   turn (an unfinished tool call is never kept: it would need a result);
 * - `refusal`: the question is asked again as a NEW request to the
 *   fallback model, with the conversation as plain text (its thinking
 *   context is lost); only the question and that answer's text are kept,
 *   so the conversation's model never changes;
 * - a model that cannot answer: nothing (the page keeps the question).
 *
 * Each question is audited (App\Assistant\AssistantLog) with its usage and
 * cost, never its text, and may trigger the monthly budget alerts.
 */
class Assistant
{
    public const REFUSED = 'No puedo ayudarte con esa pregunta. Prueba a preguntarlo de otra forma o pregunta por algo del portal.';

    public const UNAVAILABLE = 'El asistente no pudo responder en este momento. Inténtalo de nuevo en unos minutos.';

    public const TOO_MANY_ROUNDS = 'No alcancé a terminar de buscar. Intenta con una pregunta más concreta.';

    public const TRUNCATED = 'La respuesta era demasiado larga y se cortó. Intenta con una pregunta más concreta.';

    public const EMPTY = 'No tengo una respuesta para eso. Intenta preguntarlo de otra forma.';

    public function __construct(
        private readonly AssistantModel $model,
        private readonly AssistantTools $tools,
        private readonly AssistantSettings $settings,
        private readonly AssistantBudget $budget,
    ) {}

    public function plan(User $user): ConversationPlan
    {
        return ConversationPlan::for($user, $this->settings, $this->tools);
    }

    /**
     * Starts a conversation when there is none, or a new one when the
     * current one was frozen with another plan (role, model, effort or
     * tools changed). True when an existing conversation was replaced.
     */
    public function sync(User $user, Conversation $conversation): bool
    {
        $plan = $this->plan($user);

        if ($conversation->fingerprint() === $plan->fingerprint()) {
            return false;
        }

        $replaced = $conversation->exists();
        $conversation->begin($plan);

        return $replaced;
    }

    public function ask(User $user, Conversation $conversation, string $question): Answer
    {
        $this->sync($user, $conversation);
        $plan = $this->plan($user);

        $message = ['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => SystemPrompt::context($user, $plan->editor, CarbonImmutable::now())],
            ['type' => 'text', 'text' => $question],
        ]];

        $usage = new Usage;
        $run = $this->run($user, $plan, $plan->model, [...$conversation->transcript(), $message], $usage);
        $fallback = false;

        if ($run->status === Run::REFUSED && ($fallbackModel = $this->settings->fallbackFor($plan->model)) !== null) {
            $fallback = true;
            $fresh = [...self::plainHistory($conversation), $message];
            $run = $this->run($user, $plan, $fallbackModel, $fresh, $usage)->asFallback($run);
        }

        $answer = new Answer(
            text: $run->text,
            model: $run->model,
            usage: $usage,
            provider: $run->provider,
            tools: array_values(array_unique($run->tools)),
            toolCalls: count($run->tools),
            fallback: $fallback,
            refused: $run->status === Run::REFUSED,
            truncated: $run->status === Run::TRUNCATED,
            error: $run->error,
            draft: $run->draft,
        );

        if (! $answer->failed()) {
            $kept = $fallback || $run->status === Run::REFUSED
                ? [$message, self::assistantText($run->text)]
                : [$message, ...$run->kept];
            $conversation->append($question, $answer->text, $kept);
        }

        AssistantLog::asked($user, $answer);
        $this->budget->alertIfNeeded();

        return $answer;
    }

    /**
     * One model's tool loop over $messages.
     *
     * @param  list<array<string, mixed>>  $messages
     */
    private function run(User $user, ConversationPlan $plan, ModelRef $model, array $messages, Usage $usage): Run
    {
        $run = new Run($model->model, $model->provider);
        $rounds = 0;

        while (true) {
            try {
                $turn = $this->model->send(new ModelRequest($model->model, $plan->effort, $plan->maxTokens, $plan->system, $plan->tools, $messages, $model->provider));
            } catch (ModelUnavailable $unavailable) {
                return $run->ends(Run::FAILED, self::UNAVAILABLE, error: $unavailable->reason);
            }

            // Priced by the provider and model that served it.
            $usage->add($turn, $model->key());
            $run->model = $turn->model;

            if ($turn->stopReason === ModelTurn::REFUSAL) {
                return $run->ends(Run::REFUSED, self::REFUSED);
            }

            if ($turn->stopReason === ModelTurn::MAX_TOKENS) {
                $text = ($turn->text !== '' ? $turn->text."\n\n" : '').self::TRUNCATED;

                return $turn->toolCalls === []
                    ? $run->ends(Run::TRUNCATED, $text, [$turn->message()])
                    : $run->ends(Run::TRUNCATED, $text, [self::assistantText($text)]);
            }

            if ($turn->stopReason !== ModelTurn::TOOL_USE || $turn->toolCalls === []) {
                return $run->ends(Run::ANSWERED, $turn->text !== '' ? $turn->text : self::EMPTY, [$turn->message()]);
            }

            if ($rounds >= $this->settings->maxToolRounds) {
                return $run->ends(Run::TOO_MANY, self::TOO_MANY_ROUNDS, [self::assistantText(self::TOO_MANY_ROUNDS)]);
            }

            $rounds++;
            $results = [];

            foreach ($turn->toolCalls as $call) {
                $outcome = $this->tools->run($user, $call->name, $call->input);
                $run->tools[] = $call->name;
                $run->draft = $outcome->draft instanceof AnnouncementDraft ? $outcome->draft : $run->draft;
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $call->id, 'content' => $outcome->content, 'is_error' => $outcome->isError];
            }

            $step = [$turn->message(), ['role' => 'user', 'content' => $results]];
            $run->kept = [...$run->kept, ...$step];
            $messages = [...$messages, ...$step];
        }
    }

    /**
     * The conversation as plain text, for a fresh request on another model.
     *
     * @return list<array<string, mixed>>
     */
    private static function plainHistory(Conversation $conversation): array
    {
        $messages = [];

        foreach ($conversation->turns() as $turn) {
            $messages[] = ['role' => 'user', 'content' => [['type' => 'text', 'text' => $turn['question']]]];
            $messages[] = self::assistantText($turn['answer']);
        }

        return $messages;
    }

    /**
     * @return array{role: string, content: list<array{type: string, text: string}>}
     */
    private static function assistantText(string $text): array
    {
        return ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => $text]]];
    }
}
