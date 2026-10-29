<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * «Confirmar lectura» (`POST /anuncios/{announcement}/leido`): records that
 * the user read a mandatory announcement, once. A plain form on /anuncios
 * and on the home, so it works without JavaScript and from a saved copy of
 * the home (a stale button only confirms again, which changes nothing).
 * An announcement the user cannot see right now, or one that is not
 * mandatory, is not found.
 */
class AnnouncementReadController extends Controller
{
    public function store(Request $request, int $announcement): RedirectResponse
    {
        $target = Announcement::query()
            ->visibleTo($request->user())
            ->where('requires_read', true)
            ->whereKey($announcement)
            ->first(['id']);

        abort_if($target === null, 404);

        $target->markReadBy($request->user());

        return $request->input('from') === 'home'
            ? redirect()->to(route('home').'#anuncios')
            : redirect()->route('announcements.show', $target);
    }
}
