<?php

namespace App\Http\Controllers;

use App\Catalog\AppCatalog;
use App\Models\App;
use App\Models\AppLaunch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Opens an app from the portal (`/apps/{slug}`).
 *
 * - No access (or hidden): 404, so the app's existence is not revealed.
 * - Maintenance: the notice, and nothing is recorded.
 * - Local: the info page, and the launch is recorded.
 * - Web: the launch is recorded, then a redirect to the app URL.
 *
 * An app reported as failing (`external_status`) still opens. Opening in
 * a new tab (`open_in = new`) is decided by the home card markup with
 * `target="_blank" rel="noopener"`, not here.
 */
class AppLaunchController extends Controller
{
    public function __invoke(Request $request, App $app, AppCatalog $catalog): View|RedirectResponse
    {
        $user = $request->user();

        abort_unless($catalog->canOpen($user, $app), 404);

        if ($app->inMaintenance()) {
            return view('apps.maintenance', ['app' => $app]);
        }

        if ($app->isLocal()) {
            AppLaunch::record($user, $app, $request->userAgent());

            return view('apps.local', ['app' => $app]);
        }

        // The model only saves allowed URLs; checked again before leaving
        // the portal in case the allowed hosts were narrowed since.
        abort_unless(App::urlIsAllowed($app->url), 404);

        AppLaunch::record($user, $app, $request->userAgent());

        return redirect()->away($app->url);
    }
}
