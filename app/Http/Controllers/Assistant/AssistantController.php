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
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * /asistente (odd/tasks/asistente.md): the conversation of the signed-in
 * account, a plain form POST per question (works without JavaScript),
 * then a redirect back to the page. A 404 while the assistant is off
 * (App\Assistant\AssistantSwitch); while the API key is missing the page
 * says it is not available and nothing is sent.
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

    public const BUDGET_REACHED = 'El asistente alcanzó el límite de uso de este mes. Vuelve a estar disponible el 1.º del próximo mes.';

    public const RESTARTED = 'Empezamos una conversación nueva porque cambió tu cuenta o la configuración del asistente.';

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
            'remaining' => max(0, $settings->dailyQuestions - AssistantLog::questionsToday($user)),
            'dailyQuestions' => $settings->dailyQuestions,
            'conversationTurns' => $settings->conversationTurns,
            'maxLength' => $settings->questionMaxLength,
        ]);
    }

    public function ask(Request $request, AssistantSwitch $switch, AssistantSettings $settings, Assistant $assistant, AssistantBudget $budget): RedirectResponse
    {
        abort_unless($switch->enabled(), 404);

        if (! $switch->configured()) {
            return redirect()->route('assistant.show');
        }

        $question = trim((string) $request->validate([
            'pregunta' => ['required', 'string', 'max:'.$settings->questionMaxLength],
        ], [
            'pregunta.required' => 'Escribe tu pregunta.',
            'pregunta.string' => 'Escribe tu pregunta.',
            'pregunta.max' => 'Usa como máximo '.$settings->questionMaxLength.' caracteres.',
        ])['pregunta']);

        /** @var User $user */
        $user = $request->user();
        $conversation = new Conversation($request->session(), $user);

        if ($assistant->sync($user, $conversation)) {
            return redirect()->route('assistant.show')->withInput()->with('status', self::RESTARTED);
        }

        if ($conversation->isFull($settings->conversationTurns, $settings->conversationMaxBytes)) {
            return $this->refuse('Esta conversación está llena. Empieza una «Nueva conversación» para seguir preguntando.');
        }

        if ($budget->exceeded()) {
            AssistantLog::budgetBlocked($user, $budget->spentThisMonth(), $budget->limit());

            return $this->refuse(self::BUDGET_REACHED);
        }

        if (AssistantLog::questionsToday($user) >= $settings->dailyQuestions) {
            return $this->refuse("Llegaste al límite de {$settings->dailyQuestions} preguntas de hoy. Mañana puedes seguir.");
        }

        self::allowTime();

        $answer = $assistant->ask($user, $conversation, $question);

        if ($answer->failed()) {
            return $this->refuse($answer->text);
        }

        if ($answer->draft !== null) {
            $conversation->keepDraft($answer->draft);
        }

        return redirect()->to(route('assistant.show').'#respuesta');
    }

    /**
     * «Nueva conversación»: forgets the transcript, the turns and the draft.
     */
    public function clear(Request $request, AssistantSwitch $switch): RedirectResponse
    {
        abort_unless($switch->enabled(), 404);

        /** @var User $user */
        $user = $request->user();
        (new Conversation($request->session(), $user))->clear();

        return redirect()->route('assistant.show')->with('status', 'Empezaste una conversación nueva.');
    }

    private function refuse(string $message): RedirectResponse
    {
        return redirect()->route('assistant.show')->withInput()->withErrors(['pregunta' => $message]);
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
