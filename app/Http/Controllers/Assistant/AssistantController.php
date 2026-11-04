<?php

namespace App\Http\Controllers\Assistant;

use App\Assistant\Assistant;
use App\Assistant\AssistantLog;
use App\Assistant\AssistantSettings;
use App\Assistant\AssistantSwitch;
use App\Assistant\Budget\AssistantBudget;
use App\Assistant\Conversation;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\View\Assistant\ConversationJson;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * /asistente (odd/tasks/asistente.md): the conversation of the signed-in
 * account, a plain form POST per question (works without JavaScript),
 * then a redirect back to the page. A 404 while the assistant is off
 * (App\Assistant\AssistantSwitch); while the API key is missing the page
 * says it is not available and nothing is sent.
 *
 * The floating chat panel sends the same POSTs with `Accept:
 * application/json` and gets JSON instead of a redirect
 * (App\View\Assistant\ConversationJson): the same checks and messages,
 * an error as `{"error": "…"}` with 422 (the question or a full
 * conversation), 429 (the day's cap or the month's budget), 409 (the
 * conversation restarted) or 503 (not available or the model failed).
 * GET /asistente/conversacion reads the conversation for the panel.
 *
 * Before a question reaches the model, in this order: the conversation
 * must have room (it is never cut: «Nueva conversación»), the month's
 * budget must not be spent (audited when it is), and the account must be
 * under its daily cap. None of those calls the API.
 */
class AssistantController extends Controller
{
    /** Seconds PHP may run one question (several model calls and tools). */
    public const TIME_LIMIT = 180;

    public const BUDGET_REACHED = Assistant::BUDGET_REACHED;

    public const RESTARTED = 'Empezamos una conversación nueva porque cambió tu cuenta o la configuración del asistente.';

    /** What /asistente says while the API key is missing. */
    public const NOT_AVAILABLE = 'El asistente no está disponible en este momento. Vuelve a intentarlo más tarde.';

    public const CLEARED = 'Empezaste una conversación nueva.';

    public function show(Request $request, AssistantSwitch $switch, AssistantSettings $settings, Assistant $assistant, AssistantBudget $budget): View
    {
        abort_unless($switch->enabled(), 404);

        /** @var User $user */
        $user = $request->user();
        $conversation = new Conversation($request->session(), $user);
        $restarted = $assistant->sync($user, $conversation);
        $plan = $assistant->plan($user);

        return view('assistant.show', [
            'available' => $switch->configured(),
            'restarted' => $restarted,
            'secondary' => $conversation->moved(),
            'turns' => $conversation->turns(),
            'draft' => $plan->editor ? $conversation->draft() : null,
            'full' => $conversation->isFull($settings->conversationTurns, $settings->conversationMaxBytes),
            'budgetReached' => $budget->exceeded(),
            'company' => $user->company?->short_name ?: Company::displayName($user->company),
            'remaining' => self::remaining($settings, $user),
            'dailyQuestions' => $settings->dailyQuestions,
            'conversationTurns' => $settings->conversationTurns,
            'maxLength' => $settings->questionMaxLength,
        ]);
    }

    /**
     * The conversation as JSON for the floating chat panel, with what the
     * page would show around it.
     */
    public function conversation(Request $request, AssistantSwitch $switch, AssistantSettings $settings, Assistant $assistant, AssistantBudget $budget): JsonResponse
    {
        abort_unless($switch->enabled(), 404);

        /** @var User $user */
        $user = $request->user();
        $conversation = new Conversation($request->session(), $user);
        $restarted = $assistant->sync($user, $conversation);

        return response()->json([
            ...self::payload($assistant, $conversation, $settings, $user),
            'available' => $switch->configured(),
            'greeting' => ConversationJson::greeting($user),
            'restarted' => $restarted,
            'full' => $conversation->isFull($settings->conversationTurns, $settings->conversationMaxBytes),
            'budget_reached' => $budget->exceeded(),
            'daily_questions' => $settings->dailyQuestions,
            'max_length' => $settings->questionMaxLength,
        ]);
    }

    public function ask(Request $request, AssistantSwitch $switch, AssistantSettings $settings, Assistant $assistant, AssistantBudget $budget): RedirectResponse|JsonResponse
    {
        abort_unless($switch->enabled(), 404);

        if (! $switch->configured()) {
            return $request->expectsJson()
                ? self::error(self::NOT_AVAILABLE, 503)
                : redirect()->route('assistant.show');
        }

        try {
            $validated = $request->validate([
                'pregunta' => ['required', 'string', 'max:'.$settings->questionMaxLength],
            ], [
                'pregunta.required' => 'Escribe tu pregunta.',
                'pregunta.string' => 'Escribe tu pregunta.',
                'pregunta.max' => 'Usa como máximo '.$settings->questionMaxLength.' caracteres.',
            ]);
        } catch (ValidationException $e) {
            if (! $request->expectsJson()) {
                throw $e;
            }

            return self::error((string) $e->validator->errors()->first('pregunta'), 422);
        }

        $question = trim((string) $validated['pregunta']);

        /** @var User $user */
        $user = $request->user();
        $conversation = new Conversation($request->session(), $user);

        if ($assistant->sync($user, $conversation)) {
            return $request->expectsJson()
                ? self::error(self::RESTARTED, 409)
                : redirect()->route('assistant.show')->withInput()->with('status', self::RESTARTED);
        }

        if ($conversation->isFull($settings->conversationTurns, $settings->conversationMaxBytes)) {
            return $this->refuse($request, 'Esta conversación está llena. Empieza una «Nueva conversación» para seguir preguntando.', 422);
        }

        if ($budget->exceeded()) {
            AssistantLog::budgetBlocked($user, $budget->spentThisMonth(), $budget->limit());

            return $this->refuse($request, self::BUDGET_REACHED, 429);
        }

        if (AssistantLog::questionsToday($user) >= $settings->dailyQuestions) {
            return $this->refuse($request, "Llegaste al límite de {$settings->dailyQuestions} preguntas de hoy. Mañana puedes seguir.", 429);
        }

        self::allowTime();

        $answer = $assistant->ask($user, $conversation, $question);

        if ($answer->failed()) {
            return $this->refuse($request, $answer->text, 503);
        }

        if ($answer->draft !== null) {
            $conversation->keepDraft($answer->draft);
        }

        if ($request->expectsJson()) {
            return response()->json(self::payload($assistant, $conversation, $settings, $user));
        }

        return redirect()->to(route('assistant.show').'#respuesta');
    }

    /**
     * «Nueva conversación»: forgets the transcript, the turns and the draft.
     */
    public function clear(Request $request, AssistantSwitch $switch, AssistantSettings $settings): RedirectResponse|JsonResponse
    {
        abort_unless($switch->enabled(), 404);

        /** @var User $user */
        $user = $request->user();
        (new Conversation($request->session(), $user))->clear();

        if ($request->expectsJson()) {
            return response()->json([
                ...ConversationJson::payload([], null, self::remaining($settings, $user)),
                'status' => self::CLEARED,
            ]);
        }

        return redirect()->route('assistant.show')->with('status', self::CLEARED);
    }

    /**
     * A question that is not sent (or failed): back to the page with the
     * message under the field and the question kept, or the message as
     * JSON with $status.
     */
    private function refuse(Request $request, string $message, int $status): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return self::error($message, $status);
        }

        return redirect()->route('assistant.show')->withInput()->withErrors(['pregunta' => $message]);
    }

    private static function error(string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $message], $status);
    }

    /**
     * @return array{turns: list<array{role: string, html: string}>, draft: array{title: string, excerpt: string, review_url: string}|null, remaining: int, error: null}
     */
    private static function payload(Assistant $assistant, Conversation $conversation, AssistantSettings $settings, User $user): array
    {
        return ConversationJson::payload(
            $conversation->turns(),
            // Only an editor's conversation shows a draft, as on the page.
            $assistant->plan($user)->editor ? $conversation->draft() : null,
            self::remaining($settings, $user),
        );
    }

    private static function remaining(AssistantSettings $settings, User $user): int
    {
        return max(0, $settings->dailyQuestions - AssistantLog::questionsToday($user));
    }

    /**
     * Raises PHP's time limit for this request when it is lower; never
     * lowers it (the CLI and the tests run without one).
     */
    private static function allowTime(): void
    {
        $current = (int) ini_get('max_execution_time');

        if ($current !== 0 && $current < self::TIME_LIMIT && function_exists('set_time_limit')) {
            @set_time_limit(self::TIME_LIMIT);
        }
    }
}
