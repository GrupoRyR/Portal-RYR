<?php

namespace App\Assistant;

use App\Assistant\Budget\AssistantBudget;
use App\Assistant\Failover\ProviderFailover;
use App\Assistant\Model\AssistantModel;
use App\Assistant\Model\ModelRef;
use App\Assistant\Model\ModelRequest;
use App\Assistant\Model\ModelTurn;
use App\Assistant\Model\ModelUnavailable;
use App\Assistant\Tools\AnnouncementDraft;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

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
 *   When the reason fails over (App\Assistant\Failover\ProviderFailover)
 *   and a secondary is set, the conversation moves to the secondary for the
 *   rest of its life, as a text-only transcript, and the question is asked
 *   there; if that fails too, {@see BOTH_UNAVAILABLE}.
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

    public const BOTH_UNAVAILABLE = 'El asistente no está disponible en este momento.';

    public const BUDGET_REACHED = 'El asistente alcanzó el límite de uso de este mes. Vuelve a estar disponible el 1.º del próximo mes.';

    public const TOO_SLOW = 'La respuesta tardó demasiado; intenta de nuevo.';

    public function __construct(
        private readonly AssistantModel $model,
        private readonly AssistantTools $tools,
        private readonly AssistantSettings $settings,
        private readonly AssistantBudget $budget,
        private readonly ProviderFailover $failover,
    ) {}

    public function plan(User $user): ConversationPlan
    {
        return ConversationPlan::for($user, $this->settings, $this->tools);
    }

    /**
     * Starts a conversation when there is none, or a new one when the
     * current one was frozen with another plan (role, model, effort or
     * tools changed). While the primary provider is down and a secondary
     * is set, a new conversation starts on the secondary. True when an
     * existing conversation was replaced.
     */
    public function sync(User $user, Conversation $conversation): bool
    {
        $plan = $this->plan($user);

        if ($conversation->fingerprint() === $plan->fingerprint()) {
            return false;
        }

        $replaced = $conversation->exists();
        $secondary = $this->settings->secondaryFor($plan->editor);
        $conversation->begin($plan, $secondary !== null && $this->failover->isDown($plan->model->provider) ? $secondary : null);

        return $replaced;
    }

    public function ask(User $user, Conversation $conversation, string $question): Answer
    {
        $usage = new Usage;
        $answer = null;
        $model = $this->plan($user)->model;

        try {
            return $answer = $this->answer($user, $conversation, $question, $usage);
        } finally {
            // Always audited, also when something throws midway: the spend
            // and the daily count never miss a call that was made.
            AssistantLog::asked($user, $answer ?? new Answer(
                text: '',
                model: $model->model,
                usage: $usage,
                provider: $model->provider,
                error: 'exception',
            ));
            $this->budget->alertIfNeeded();
        }
    }

    private function answer(User $user, Conversation $conversation, string $question, Usage $usage): Answer
    {
        $this->sync($user, $conversation);
        $plan = $this->plan($user);
        $secondary = $this->settings->secondaryFor($plan->editor);
        $guard = new QuestionGuard(
            CarbonImmutable::now()->addSeconds($this->settings->deadlineSeconds),
            $this->budget->limit(),
            $this->budget->spentThisMonth(),
        );

        $message = ['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => SystemPrompt::context($user, $plan->editor, CarbonImmutable::now())],
            ['type' => 'text', 'text' => $question],
        ]];

        $failover = $conversation->moved() && $secondary !== null;
        $serving = $failover ? $plan->on($secondary) : $plan;
        $moveOnSuccess = false;
        $run = null;

        // While the primary is down (the breaker), straight to the secondary.
        $skipPrimary = ! $failover && $secondary !== null && $this->failover->isDown($plan->model->provider);

        if (! $skipPrimary) {
            $run = $this->run($user, $serving, $serving->model, [...$conversation->transcript(), $message], $usage, $guard);
        }

        if ($skipPrimary || ($run->status === Run::FAILED && ! $failover && $secondary !== null && ProviderFailover::kind($run->error) !== null)) {
            $reason = $run === null ? ($this->failover->reason($plan->model->provider) ?? 'cooldown') : (string) $run->error;

            if ($run !== null) {
                $this->failover->markDown($plan->model->provider, $reason);
            }

            AssistantLog::failover($user, $plan->model->key(), $secondary->key(), $reason);
            $failover = true;
            $serving = $plan->on($secondary);
            // The secondary cannot read the primary's blocks: the conversation as plain text.
            $secondaryRun = $this->run($user, $serving, $secondary, [...$conversation->plainTranscript(), $message], $usage, $guard);
            $run = $run === null ? $secondaryRun : $secondaryRun->asFallback($run);

            if ($run->status === Run::FAILED) {
                $run->text = self::BOTH_UNAVAILABLE;
            } else {
                // Moved only once the secondary answered: a failure of both keeps the history.
                $moveOnSuccess = true;
            }
        } elseif ($run->status === Run::FAILED && ProviderFailover::kind($run->error) === null) {
            // A request the provider rejects (400, 404, 413...) is a bug: never a failover.
            Log::error('The assistant model rejected the request.', ['model' => $serving->model->key(), 'reason' => $run->error, 'user_id' => $user->id]);
        }

        $fallback = false;

        if ($run->status === Run::REFUSED && ($fallbackModel = $this->settings->fallbackFor($serving->model)) !== null) {
            $fallback = true;
            $fresh = [...$conversation->plainTranscript(), $message];
            $run = $this->run($user, $serving, $fallbackModel, $fresh, $usage, $guard)->asFallback($run);
        }

        if ($run->status === Run::BUDGET) {
            AssistantLog::budgetBlocked($user, $guard->spentBefore + $usage->costUsd, $guard->budget);
        }

        $answer = new Answer(
            text: $run->text,
            model: $run->model,
            usage: $usage,
            provider: $run->provider,
            tools: array_values(array_unique($run->tools)),
            toolCalls: count($run->tools),
            fallback: $fallback,
            failover: $failover,
            refused: $run->status === Run::REFUSED,
            truncated: $run->status === Run::TRUNCATED,
            error: $run->error,
            draft: $run->draft,
            stopped: in_array($run->status, [Run::BUDGET, Run::DEADLINE], true) ? $run->status : null,
        );

        if (! $answer->failed()) {
            if ($moveOnSuccess) {
                $conversation->moveTo($secondary);
            }

            $kept = $fallback || $run->status === Run::REFUSED
                ? [$message, self::assistantText($run->text)]
                : [$message, ...$run->kept];
            $conversation->append($question, $answer->text, $kept);
        }

        return $answer;
    }

    /**
     * One model's tool loop over $messages. Before every call: the
     * question's deadline and the month's budget (spend so far plus this
     * question's), each call limited to the time left.
     *
     * @param  list<array<string, mixed>>  $messages
     */
    private function run(User $user, ConversationPlan $plan, ModelRef $model, array $messages, Usage $usage, QuestionGuard $guard): Run
    {
        $run = new Run($model->model, $model->provider);
        $rounds = 0;

        while (true) {
            if ($guard->budgetReached($usage)) {
                return $run->ends(Run::BUDGET, self::BUDGET_REACHED, [self::assistantText(self::BUDGET_REACHED)]);
            }

            if ($guard->expired()) {
                return $run->ends(Run::DEADLINE, self::TOO_SLOW, [self::assistantText(self::TOO_SLOW)]);
            }

            try {
                $turn = $this->model->send(new ModelRequest(
                    $model->model, $plan->effort, $plan->maxTokens, $plan->system, $plan->tools, $messages, $model->provider, $guard->secondsLeft(),
                ));
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

                // An unfinished tool call, or a turn with no text, is never kept.
                return $turn->toolCalls === [] && $turn->text !== ''
                    ? $run->ends(Run::TRUNCATED, $text, [$turn->message()])
                    : $run->ends(Run::TRUNCATED, $text, [self::assistantText($text)]);
            }

            if ($turn->stopReason !== ModelTurn::TOOL_USE || $turn->toolCalls === []) {
                // A turn with no text and no tool call would be an empty
                // assistant message on the next request (a 400): keep the
                // page's text instead.
                return $turn->text !== ''
                    ? $run->ends(Run::ANSWERED, $turn->text, [$turn->message()])
                    : $run->ends(Run::ANSWERED, self::EMPTY, [self::assistantText(self::EMPTY)]);
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
     * @return array{role: string, content: list<array{type: string, text: string}>}
     */
    private static function assistantText(string $text): array
    {
        return ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => $text]]];
    }
}
