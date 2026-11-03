<?php

namespace App\Http\Controllers\Assistant;

use App\Announcements\AnnouncementListEmails;
use App\Announcements\AnnouncementPublisher;
use App\Assistant\AssistantLog;
use App\Assistant\AssistantSettings;
use App\Assistant\AssistantSwitch;
use App\Assistant\Conversation;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * «Publicar» and «Descartar» of the announcement draft the assistant
 * proposed (App\Assistant\Tools\BorradorDeAnuncio). Publishing is the
 * person's own CSRF POST of the form on /asistente, with the confirmation
 * box ticked: the model never reaches this code.
 *
 * It goes the way the announcement editor (App\Livewire\Admin\AnnouncementEditor)
 * saves a new one: the policy (`create`, then `publishTo` for the
 * audience), the editor's rules for the title and the text, and
 * App\Announcements\AnnouncementPublisher. The audience is the signed-in
 * account's company, from now on, without an end, pin, image, list email
 * or phone notice; anything else is set in Administración › Anuncios.
 * Only with a draft in the session: a POST without one is a 404.
 */
class AssistantDraftController extends Controller
{
    public function publish(
        Request $request,
        AssistantSwitch $switch,
        AssistantSettings $settings,
        AnnouncementPublisher $publisher,
        AnnouncementListEmails $listEmails,
    ): RedirectResponse {
        abort_unless($switch->enabled(), 404);

        /** @var User $user */
        $user = $request->user();
        $conversation = new Conversation($request->session(), $user, $settings->historyTurns);

        abort_if($conversation->draft() === null, 404);
        Gate::authorize('create', Announcement::class);
        abort_if($user->company_id === null, 403);

        $companyIds = [(int) $user->company_id];
        Gate::authorize('publishTo', [Announcement::class, Announcement::AUDIENCE_COMPANIES, $companyIds]);

        $request->merge([
            'title' => trim((string) $request->input('title')),
            'body' => trim((string) $request->input('body')),
        ]);

        $data = $request->validate([
            // The editor's rules: one line, the title also goes in emails read as Markdown.
            'title' => ['required', 'string', 'max:'.Announcement::TITLE_MAX, 'not_regex:/[\r\n]/'],
            'body' => ['required', 'string', 'max:'.Announcement::BODY_MAX],
            'requires_read' => ['sometimes', 'boolean'],
            'confirm' => ['accepted'],
        ], [
            'title.required' => 'Escribe el título.',
            'title.max' => 'Usa como máximo '.Announcement::TITLE_MAX.' caracteres.',
            'title.not_regex' => 'El título va en una sola línea.',
            'body.required' => 'Escribe el texto del anuncio.',
            'body.max' => 'Usa como máximo '.Announcement::BODY_MAX.' caracteres.',
            'confirm.accepted' => 'Marca la casilla para confirmar que quieres publicarlo.',
        ]);

        $announcement = $publisher->save(new Announcement, [
            'title' => $data['title'],
            'body' => $data['body'],
            'audience' => Announcement::AUDIENCE_COMPANIES,
            // Like a new announcement in the editor: from this minute on.
            'starts_at' => Carbon::now()->startOfMinute(),
            'ends_at' => null,
            'is_pinned' => false,
            'requires_read' => $request->boolean('requires_read'),
            'email_lists' => false,
            'push_phone' => false,
            'author_id' => $user->id,
        ], $companyIds);

        // As the editor does after saving; without «email_lists» it sends nothing.
        $listEmails->queue($announcement);

        AssistantLog::draftPublished($user, $announcement);
        $conversation->forgetDraft();

        return redirect()->route('assistant.show')
            ->with('status', 'Publicaste el anuncio «'.$announcement->title.'».')
            ->with('published', $announcement->id);
    }

    public function discard(Request $request, AssistantSwitch $switch, AssistantSettings $settings): RedirectResponse
    {
        abort_unless($switch->enabled(), 404);

        /** @var User $user */
        $user = $request->user();
        (new Conversation($request->session(), $user, $settings->historyTurns))->forgetDraft();

        return redirect()->route('assistant.show')->with('status', 'Descartaste el borrador.');
    }
}
