<?php

namespace App\Assistant;

use App\Assistant\Model\AssistantModel;
use App\Assistant\Model\ModelRequest;
use App\Assistant\Model\ModelTurn;
use App\Assistant\Model\ModelUnavailable;
use App\Assistant\Tools\AnnouncementDraft;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Answers one question of the signed-in account:
 *
 * 1. sends the session's past turns (text only) and the question, with the
 *    tools that account gets (App\Assistant\AssistantTools);
 * 2. while the model asks for tools, runs them for that account and sends
 *    the results back, at most {@see AssistantSettings::$maxToolRounds}
 *    rounds;
 * 3. when the main model ends with `refusal`, sends the same request to
 *    the fallback model once and goes on with it for the rest of the
 *    question; a second refusal is the answer {@see REFUSED};
 * 4. audits the question (App\Assistant\AssistantLog), never its text.
 *
 * A model that cannot answer (ModelUnavailable) gives {@see UNAVAILABLE},
 * audited with its reason. The session is the caller's.
 */
class Assistant
{
    public const REFUSED = 'No puedo ayudarte con esa pregunta. Prueba a preguntarlo de otra forma o pregunta por algo del portal.';

    public const UNAVAILABLE = 'El asistente no pudo responder en este momento. Inténtalo de nuevo en unos minutos.';

    public const TOO_MANY_ROUNDS = 'No alcancé a terminar de buscar. Intenta con una pregunta más concreta.';

    public const EMPTY = 'No tengo una respuesta para eso. Intenta preguntarlo de otra forma.';

    public function __construct(
        private readonly AssistantModel $model,
        private readonly AssistantTools $tools,
        private readonly AssistantSettings $settings,
    ) {}

    public function ask(User $user, Conversation $conversation, string $question): Answer
    {
        $answer = $this->answer($user, $conversation, $question);

        AssistantLog::asked($user, $answer);

        return $answer;
    }

    private function answer(User $user, Conversation $conversation, string $question): Answer
    {
        $model = $this->settings->model;
        $system = SystemPrompt::for($user, CarbonImmutable::now());
        $tools = $this->tools->definitions($user);
        $messages = [...$conversation->messages(), ['role' => 'user', 'content' => $question]];

        $used = [];
        $calls = 0;
        $rounds = 0;
        $input = 0;
        $output = 0;
        $fallback = false;
        $draft = null;

        // By reference: the counters as they are when the question ends.
        $finish = function (string $text, string $answeredBy, bool $refused = false, ?string $error = null) use (&$used, &$calls, &$input, &$output, &$fallback, &$draft): Answer {
            return new Answer(
                text: $text,
                model: $answeredBy,
                tools: array_values(array_unique($used)),
                toolCalls: $calls,
                inputTokens: $input,
                outputTokens: $output,
                fallback: $fallback,
                refused: $refused,
                error: $error,
                draft: $draft,
            );
        };

        while (true) {
            try {
                $turn = $this->model->send(new ModelRequest($model, $this->settings->effort, $this->settings->maxTokens, $system, $tools, $messages));
            } catch (ModelUnavailable $unavailable) {
                return $finish(self::UNAVAILABLE, $model, error: $unavailable->reason);
            }

            $input += $turn->inputTokens;
            $output += $turn->outputTokens;

            if ($turn->stopReason === ModelTurn::REFUSAL) {
                if (! $fallback && $this->settings->fallbackModel !== null) {
                    $fallback = true;
                    $model = $this->settings->fallbackModel;

                    continue;
                }

                return $finish(self::REFUSED, $turn->model, refused: true);
            }

            if ($turn->stopReason !== ModelTurn::TOOL_USE || $turn->toolCalls === []) {
                return $finish($turn->text !== '' ? $turn->text : self::EMPTY, $turn->model);
            }

            if ($rounds >= $this->settings->maxToolRounds) {
                return $finish(self::TOO_MANY_ROUNDS, $turn->model);
            }

            $rounds++;
            $results = [];

            foreach ($turn->toolCalls as $call) {
                $outcome = $this->tools->run($user, $call->name, $call->input);
                $used[] = $call->name;
                $calls++;
                $draft = $outcome->draft instanceof AnnouncementDraft ? $outcome->draft : $draft;
                $results[] = ['id' => $call->id, 'content' => $outcome->content, 'isError' => $outcome->isError];
            }

            $messages[] = ['role' => 'assistant', 'turn' => $turn];
            $messages[] = ['role' => 'user', 'toolResults' => $results];
        }
    }
}
