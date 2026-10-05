<?php

namespace App\Http\Controllers\Auth;

use App\Auth\LoginFailed;
use App\Auth\MicrosoftIdentity;
use App\Auth\MicrosoftLogin;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
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

    public function callback(Request $request, MicrosoftLogin $login): RedirectResponse
    {
        try {
            $driver = Socialite::driver('microsoft');
            $identity = MicrosoftIdentity::fromSocialite($driver->user(), $driver->getClaims());
            $user = $login->handle($identity);
        } catch (LoginFailed $e) {
            return $this->failed($e->getMessage());
        } catch (Throwable $e) {
            Log::warning('Microsoft sign-in failed.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $this->failed(LoginFailed::RETRY);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    private function failed(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['login' => $message]);
    }
}
