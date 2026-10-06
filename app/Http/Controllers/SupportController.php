<?php

namespace App\Http\Controllers;

use App\Catalog\AppCatalog;
use App\Models\App;
use App\Settings\PortalSettings;
use App\Support\SupportLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * «Soporte TI» (`/soporte`, brief §4): sends the person to the ticket
 * platform of Configuración with `origen=portal`, their company slug and,
 * when known, an app slug. Never personal data.
 *
 * The app is the one of the page the button was on (`?app=`, local app or
 * maintenance notice) when the person can see it; otherwise the last app
 * they opened in the last 30 minutes that they can still see; otherwise
 * none. Without a support URL there is no button and this is a 404.
 */
class SupportController extends Controller
{
    public const RECENT_MINUTES = 30;

    public function __invoke(Request $request, PortalSettings $settings, AppCatalog $catalog): RedirectResponse
    {
        $url = $settings->supportUrl();

        abort_if($url === null, 404);

        $user = $request->user();
        $slug = $request->query('app');

        $app = is_string($slug) ? App::query()->where('slug', $slug)->first() : null;

        if ($app === null || ! $catalog->canOpen($user, $app)) {
            $app = $catalog->lastOpenedWithin($user, self::RECENT_MINUTES);
        }

        return redirect()->away(SupportLink::build($url, $user->company?->slug, $app?->slug));
    }
}
