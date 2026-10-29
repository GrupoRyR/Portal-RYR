<?php

namespace App\Http\Controllers\Auth;

use App\Audit\SecurityLog;
use App\Auth\AccountIntent;
use App\Auth\LinkedAccounts;
use App\Auth\LinkRefused;
use App\Auth\LoginFailed;
use App\Auth\MicrosoftIdentity;
use App\Auth\MicrosoftLogin;
use App\Auth\RecentMicrosoftLogin;
use App\Http\Controllers\Controller;
use App\Microsoft\ConsentChecker;
use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

    public const MICROSOFT_ERROR_MESSAGE = 'Microsoft no confirmó la cuenta. Inténtalo de nuevo.';

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
    public function confirm(Request $request, RecentMicrosoftLogin $recent, AccountIntent $intents): SymfonyRedirect|Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->is_emergency || $user->azure_oid === null) {
            return response()->view('errors.microsoft-required', [], 403);
        }

        // Only one Microsoft round trip is pending at a time.
        $intents->discard();
        $recent->start($user, (string) $request->query('return', '/'));

        return Socialite::driver('microsoft')
            ->setScopes(['openid', 'profile', 'email', 'User.Read'])
            ->with(['prompt' => 'login', 'login_hint' => $user->microsoft_login_hint ?? (string) $user->email])
            ->redirect();
    }

    /**
     * In this order: admin consent, an error from Microsoft, an account
     * intent, a re-authentication, a sign-in. Each step ends the callback.
     */
    public function callback(
        Request $request,
        MicrosoftLogin $login,
        RecentMicrosoftLogin $recent,
        AccountIntent $intents,
        LinkedAccounts $linkedAccounts,
    ): RedirectResponse|View|Response {
        // A tenant administrator coming back from the admin-consent link.
        // Nothing is trusted from these parameters: the wizard verifies the
        // consent itself with ConsentChecker.
        if ($request->query('state') === ConsentChecker::ADMIN_CONSENT_STATE) {
            return view('auth.admin-consent', [
                'granted' => $request->query('admin_consent') === 'True' && ! $request->has('error'),
            ]);
        }

        if ($request->has('error')) {
            return $this->microsoftError($request, $intents);
        }

        // The callback is shared with the re-authentication flow and the
        // account intents, so it is not behind the guest middleware.
        if ($request->user() instanceof User) {
            return $intents->pending()
                ? $this->continueIntent($request, $request->user(), $intents, $login, $linkedAccounts)
                : $this->confirmed($request, $request->user(), $recent);
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
        $recent->confirm($user);

        return redirect()->intended('/');
    }

    /**
     * Microsoft sent the user back with an `error` instead of a code: they
     * cancelled, or Microsoft could not complete the sign-in. A signed-in
     * user keeps the session and leaves any pending flow; a guest sees the
     * usual failure. Only the error code reaches the log, never
     * `error_description`: anyone can put text in these parameters.
     */
    private function microsoftError(Request $request, AccountIntent $intents): RedirectResponse
    {
        $user = $request->user();
        $error = $request->query('error');

        Log::info('Microsoft returned an error to the sign-in callback.', [
            'error' => is_string($error) ? substr((string) preg_replace('/[^A-Za-z0-9_]/', '', $error), 0, 64) : null,
            'user_id' => $user?->getAuthIdentifier(),
        ]);

        if (! $user instanceof User) {
            SecurityLog::microsoftRejected('microsoft_error', null);

            return $this->failed(LoginFailed::RETRY);
        }

        $intents->discard();

        // A re-authentication that ends in an error is still a failed
        // confirmation of a sensitive action, so Auditoría records it.
        $pending = $request->session()->pull(RecentMicrosoftLogin::PENDING_KEY);

        if (is_array($pending) && ($pending['user'] ?? null) === $user->getKey()) {
            SecurityLog::reauthentication($user, false, $request->userAgent());
        }

        // Always home: the page a pending re-authentication would return to
        // is behind microsoft.recent and would send the user straight back
        // to Microsoft.
        return redirect('/')->with('status', self::MICROSOFT_ERROR_MESSAGE);
    }

    /**
     * The callback of an account intent (App\Auth\AccountIntent) ends here,
     * whatever happens: it is never a re-authentication or a sign-in.
     */
    private function continueIntent(
        Request $request,
        User $user,
        AccountIntent $intents,
        MicrosoftLogin $login,
        LinkedAccounts $linkedAccounts,
    ): RedirectResponse {
        $intent = $intents->pull($request, $user);

        // One handler per flow type (linking; switching company comes
        // next); without one, or when the intent failed its checks, it is
        // dropped.
        return match ($intent['type'] ?? null) {
            AccountIntent::LINK => $this->linkAccount($user, $login, $linkedAccounts),
            default => $this->unusableIntent($user),
        };
    }

    /**
     * Microsoft answered with the account the person chose to link. Every
     * check runs before any write; then admit() and link() share one
     * transaction, so a refusal leaves no account, person or link behind.
     * The other account is proven, never signed in: the session, the
     * recent confirmation and its sign-in record stay as they were.
     */
    private function linkAccount(User $user, MicrosoftLogin $login, LinkedAccounts $linkedAccounts): RedirectResponse
    {
        $identity = null;

        try {
            $driver = Socialite::driver('microsoft');
            $identity = MicrosoftIdentity::fromSocialite($driver->user(), $driver->getClaims());

            $linkedAccounts->check($user, $login->preview($identity));

            $other = DB::transaction(function () use ($user, $identity, $login, $linkedAccounts) {
                $other = $login->admit($identity)['user'];
                $linkedAccounts->link($user, $other);

                return $other;
            });
        } catch (LinkRefused $e) {
            return $this->linkRefused($user, $e, $identity);
        } catch (LoginFailed $e) {
            return $this->linkRefused($user, new LinkRefused($e->reason), $identity);
        } catch (Throwable $e) {
            Log::warning('Linking a Microsoft account failed.', [
                'user_id' => $user->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $this->linkRefused($user, new LinkRefused('provider_error'), $identity);
        }

        return redirect('/')->with('status', 'Vinculaste tu cuenta de '.Company::displayName($other->company).'.');
    }

    private function linkRefused(User $user, LinkRefused $refusal, ?MicrosoftIdentity $identity): RedirectResponse
    {
        SecurityLog::accountLinkRefused($user, $refusal->reason, $identity?->tenantId);

        return redirect('/')->with('status', $refusal->getMessage());
    }

    private function unusableIntent(User $user): RedirectResponse
    {
        Log::warning('Microsoft callback for an account intent that is expired, foreign or not handled; discarded.', ['user_id' => $user->id]);

        return redirect('/')->with('status', AccountIntent::UNUSABLE_MESSAGE);
    }

    private function confirmed(Request $request, User $user, RecentMicrosoftLogin $recent): RedirectResponse|Response
    {
        $pending = $recent->pull($user);

        if ($pending === null) {
            return redirect('/');
        }

        $identity = null;

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
        $recent->confirm($user);

        // The same account just answered: refresh whether it is a B2B guest
        // (linked accounts refuse guests and accounts not known yet).
        if ($identity !== null) {
            $user->forceFill(['is_guest' => ! $identity->isHomeSignIn()])->save();
        }

        return redirect($pending['return']);
    }

    private function failed(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['login' => $message]);
    }
}
