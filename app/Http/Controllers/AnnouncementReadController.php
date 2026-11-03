<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * «Confirmar lectura» (`POST /anuncios/{announcement}/leido`): records that
 * the account read a mandatory announcement, once. A plain form on
 * /anuncios and on the home, so it works without JavaScript and from a
 * saved copy of the home.
 *
 * - Visible now and mandatory: recorded (again changes nothing).
 * - Shown to the account's company once but no longer asking (withdrawn,
 *   expired or no longer mandatory; a stale button): back to where it came
 *   from with a short message, nothing recorded.
 * - Anything the account could never see (another company's, not started
 *   yet, missing, or an account without a company): not found, so the
 *   answer never tells that another company's announcement exists.
 */
class AnnouncementReadController extends Controller
{
    public const NO_LONGER_ASKS = 'Este anuncio ya no pide confirmación.';

    public function store(Request $request, int $announcement): RedirectResponse
    {
        $user = $request->user();

        $target = Announcement::query()
            ->visibleTo($user)
            ->where('requires_read', true)
            ->whereKey($announcement)
            ->first(['id']);

        if ($target !== null) {
            $target->markReadBy($user);

            return $this->back($request, redirect()->route('announcements.show', $target));
        }

        $companyId = $user->company_id === null ? null : (int) $user->company_id;
        abort_unless($this->wasShownToCompanyOf($companyId, $announcement), 404);

        return $this->back($request, redirect()->route('announcements.index'))
            ->with('status', self::NO_LONGER_ASKS);
    }

    /**
     * Addressed to the company and already started: the account's company
     * could have seen it, so saying it no longer asks reveals nothing.
     */
    private function wasShownToCompanyOf(?int $companyId, int $announcement): bool
    {
        return $companyId !== null && Announcement::query()
            ->forCompanyId($companyId)
            ->where('starts_at', '<=', Carbon::now())
            ->whereKey($announcement)
            ->exists();
    }

    private function back(Request $request, RedirectResponse $otherwise): RedirectResponse
    {
        return $request->input('from') === 'home'
            ? redirect()->to(route('home').'#anuncios')
            : $otherwise;
    }
}
