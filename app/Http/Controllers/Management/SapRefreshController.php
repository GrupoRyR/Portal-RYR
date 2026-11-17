<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Management\SapFigures;
use App\Management\SapRefresh;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * «Actualizar ventas y cartera» (`POST /panel-gerencial/actualizar`,
 * odd/tasks/panel-gerencial-sap.md T3): a plain form of the «Panel
 * gerencial», so it works without JavaScript. The route sits behind `auth`,
 * CSRF, `can:viewManagementPanel` (Gerencia of the account's own company:
 * 403 for anyone else) and a rate limit of its own.
 *
 * The request goes from the server, for the account's own company only
 * (App\Management\SapRefresh); back to the panel with a plain message
 * flashed as FLASH_KEY. 404 when the panel has no SAP figures to update:
 * the API or the views are not configured, or the company has no SAP code.
 */
class SapRefreshController extends Controller
{
    /** Its own bucket: `throttle:N,1` routes would share one counter per account. */
    public const PER_MINUTE = 6;

    /** The session key of the message, shown inside the panel (sap-panel.blade.php). */
    public const FLASH_KEY = 'management_status';

    public function __invoke(Request $request, SapRefresh $refresh, SapFigures $figures): RedirectResponse
    {
        $user = $request->user();
        $company = $user instanceof User ? $user->company?->sapCode() : null;

        abort_if($company === null || ! $figures->configured() || ! $refresh->configured(), 404);

        return redirect()->to(route('home').'#panel-gerencial')
            ->with(self::FLASH_KEY, $refresh->request($user, $company)->message());
    }
}
