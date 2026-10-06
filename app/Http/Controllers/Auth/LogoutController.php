<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Settings\PortalSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutController extends Controller
{
    /**
     * Ends the portal session. By default the Microsoft session is left
     * alone; with «Al salir, cerrar también Microsoft» on (Configuración),
     * a Microsoft user is sent to the Microsoft sign-out page, which comes
     * back to the portal sign-in page when that address is registered as a
     * logout URL of the app registration. Emergency accounts are local.
     */
    public function __invoke(Request $request, PortalSettings $settings): RedirectResponse
    {
        $user = $request->user();
        $toMicrosoft = $settings->signsOutOfMicrosoft() && $user instanceof User && ! $user->is_emergency;

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($toMicrosoft) {
            return redirect()->away(sprintf(
                'https://login.microsoftonline.com/%s/oauth2/v2.0/logout?post_logout_redirect_uri=%s',
                rawurlencode((string) config('services.microsoft.tenant', 'organizations')),
                urlencode(route('login')),
            ));
        }

        return redirect()->route('login');
    }
}
