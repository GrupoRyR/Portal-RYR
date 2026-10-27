<?php

namespace App\Http\Controllers\Auth;

use App\Audit\SecurityLog;
use App\Auth\LoginFailed;
use App\Auth\MicrosoftIdentity;
use App\Auth\MicrosoftLogin;
use App\Auth\RecentMicrosoftLogin;
use App\Http\Controllers\Controller;
use App\Microsoft\ConsentChecker;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class MicrosoftLoginController extends Controller
{
    /**
     * Delegated Graph permissions the portal needs (brief, section 3).
     */
    public const SCOPES = ['openid', 'profile', 'email', 'User.Read', 'GroupMember.Read.All'];

    public function show(): View
    {
        return view('auth.login');
    }

    public function redirect(): SymfonyRedirect
    {
        return Socialite::driver('microsoft')->setScopes(self::SCOPES)->redirect();
    }

    /**
     * Re-authentication for sensitive actions: Microsoft must ask for the
     * credentials again (prompt=login), whatever its own session says.
     */
    public function confirm(Request $request, RecentMicrosoftLogin $recent): SymfonyRedirect|Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->is_emergency || $user->azure_oid === null) {
            return response()->view('errors.microsoft-required', [], 403);
        }

        $recent->start($user, (string) $request->query('return', '/'));

        return Socialite::driver('microsoft')
            ->setScopes(['openid', 'profile', 'email', 'User.Read'])
            ->with(['prompt' => 'login', 'login_hint' => (string) $user->email])
            ->redirect();
    }

    public function callback(Request $request, MicrosoftLogin $login, RecentMicrosoftLogin $recent): RedirectResponse|View|Response
    {
        // A tenant administrator coming back from the admin-consent link.
        // Nothing is trusted from these parameters: the wizard verifies the
        // consent itself with ConsentChecker.
        if ($request->query('state') === ConsentChecker::ADMIN_CONSENT_STATE) {
            return view('auth.admin-consent', [
                'granted' => $request->query('admin_consent') === 'True' && ! $request->has('error'),
            ]);
        }

        // The callback is shared with the re-authentication flow, so it is
        // not behind the guest middleware.
        if ($request->user() instanceof User) {
            return $this->confirmed($request, $request->user(), $recent);
        }

        // Every rejection is also a security event (Auditoría) with a reason
        // code and, when the token got that far, the tenant ID.
        $identity = null;

        try {
            $driver = Socialite::driver('microsoft');
            $identity = MicrosoftIdentity::fromSocialite($driver->user(), $driver->getClaims());
            $user = $login->handle($identity, $request->userAgent());
        } catch (LoginFailed $e) {
            SecurityLog::microsoftRejected($e->reason, $identity?->tenantId);

            return $this->failed($e->getMessage());
        } catch (Throwable $e) {
            Log::warning('Microsoft sign-in failed.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            SecurityLog::microsoftRejected('provider_error', $identity?->tenantId);

            return $this->failed(LoginFailed::RETRY);
        }

        Auth::login($user);
        $request->session()->regenerate();

        // A completed Microsoft sign-in is itself a fresh confirmation: the
        // sensitive company actions only ask Microsoft again once it is older
        // than RecentMicrosoftLogin::TTL_SECONDS (decision 2026-10-05).
        $recent->confirm();

        return redirect()->intended('/');
    }

    private function confirmed(Request $request, User $user, RecentMicrosoftLogin $recent): RedirectResponse|Response
    {
        $pending = $recent->pull($user);

        if ($pending === null) {
            return redirect('/');
        }

        try {
            $driver = Socialite::driver('microsoft');
            $identity = MicrosoftIdentity::fromSocialite($driver->user(), $driver->getClaims());
            $outcome = $recent->verify($user, $identity);
        } catch (Throwable $e) {
            Log::warning('Microsoft re-authentication failed.', ['user_id' => $user->id, 'exception' => $e::class]);
            $outcome = RecentMicrosoftLogin::REJECTED;
        }

        if ($outcome === RecentMicrosoftLogin::MISSING_AUTH_TIME) {
            // A setup problem, not an attack: the signed-in user answered,
            // so the session stays; nothing is confirmed.
            Log::error('Microsoft re-authentication cannot be checked: the id_token has no auth_time. Add the auth_time optional claim (ID token) to the app registration.', ['user_id' => $user->id]);
            SecurityLog::reauthenticationUnavailable($user, RecentMicrosoftLogin::MISSING_AUTH_TIME, $request->userAgent());

            return response()->view('errors.reauth-unavailable', [], 503);
        }

        $matches = $outcome === RecentMicrosoftLogin::CONFIRMED;

        SecurityLog::reauthentication($user, $matches, $request->userAgent());

        if (! $matches) {
            // Someone else's Microsoft account, a sign-in that was not just
            // made, or a failed one answered for this session: end it rather
            // than trust it.
            Log::warning('Microsoft re-authentication did not confirm the signed-in user; signing out.', ['user_id' => $user->id]);

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $this->failed(LoginFailed::RETRY);
        }

        $request->session()->regenerate();
        $recent->confirm();

        return redirect($pending['return']);
    }

    private function failed(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['login' => $message]);
    }
}
