<?php

namespace App\Http\Controllers\Assistant;

use App\Assistant\Assistant;
use App\Assistant\AssistantLog;
use App\Assistant\AssistantSettings;
use App\Assistant\AssistantSwitch;
use App\Assistant\Conversation;
use App\Assistant\Tools\BorradorDeAnuncio;
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
 * Each question is checked against the daily cap first (the account's
 * `assistant.asked` lines of today), so a question over it never reaches
 * the model and is not audited.
 */
class AssistantController extends Controller
{
    /** Seconds PHP may run one question (several model calls and tools). */
    public const TIME_LIMIT = 180;

    public function show(Request $request, AssistantSwitch $switch, AssistantSettings $settings): View
    {
        abort_unless($switch->enabled(), 404);

        /** @var User $user */
        $user = $request->user();
        $conversation = $this->conversation($request, $settings);
        $draft = (new BorradorDeAnuncio)->availableTo($user) ? $conversation->draft() : null;

        return view('assistant.show', [
            'available' => $switch->configured(),
            'turns' => $conversation->turns(),
            'draft' => $draft,
            'company' => $user->company?->short_name ?: Company::displayName($user->company),
            'remaining' => max(0, $settings->dailyQuestions - AssistantLog::questionsToday($user)),
            'dailyQuestions' => $settings->dailyQuestions,
            'maxLength' => $settings->questionMaxLength,
        ]);
    }

    public function ask(Request $request, AssistantSwitch $switch, AssistantSettings $settings, Assistant $assistant): RedirectResponse
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

        if (AssistantLog::questionsToday($user) >= $settings->dailyQuestions) {
            return redirect()->route('assistant.show')
                ->withInput()
                ->withErrors(['pregunta' => "Llegaste al límite de {$settings->dailyQuestions} preguntas de hoy. Mañana puedes seguir."]);
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(self::TIME_LIMIT);
        }

        $conversation = $this->conversation($request, $settings);
        $answer = $assistant->ask($user, $conversation, $question);

        if ($answer->failed()) {
            return redirect()->route('assistant.show')
                ->withInput()
                ->withErrors(['pregunta' => $answer->text]);
        }

        $conversation->add($question, $answer->text);

        if ($answer->draft !== null) {
            $conversation->keepDraft($answer->draft);
        }

        return redirect()->to(route('assistant.show').'#respuesta');
    }

    /**
     * «Nueva conversación»: forgets the turns and the draft of the session.
     */
    public function clear(Request $request, AssistantSwitch $switch, AssistantSettings $settings): RedirectResponse
    {
        abort_unless($switch->enabled(), 404);

        $this->conversation($request, $settings)->clear();

        return redirect()->route('assistant.show')->with('status', 'Empezaste una conversación nueva.');
    }

    private function conversation(Request $request, AssistantSettings $settings): Conversation
    {
        /** @var User $user */
        $user = $request->user();

        return new Conversation($request->session(), $user, $settings->historyTurns);
    }
}
