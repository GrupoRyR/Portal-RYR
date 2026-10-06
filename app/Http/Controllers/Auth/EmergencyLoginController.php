<?php

namespace App\Http\Controllers\Auth;

use App\Auth\EmergencyLogin;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Unlinked emergency sign-in. Every failure redirects to step 1 with the
 * same message, so it never tells which step or field was wrong.
 */
class EmergencyLoginController extends Controller
{
    public const FAILURE_MESSAGE = 'Credenciales inválidas.';

    public function __construct(private readonly EmergencyLogin $login) {}

    public function show(): View
    {
        return view('auth.emergency-login');
    }

    public function credentials(Request $request): RedirectResponse
    {
        $passed = $this->login->attemptCredentials(
            $request,
            (string) $request->input('email', ''),
            (string) $request->input('password', ''),
        );

        return $passed ? redirect()->route('emergency.code') : $this->failed();
    }

    public function showCode(Request $request): View|RedirectResponse
    {
        if (! $this->login->hasPendingStep($request)) {
            return redirect()->route('emergency.login');
        }

        return view('auth.emergency-code');
    }

    public function code(Request $request): RedirectResponse
    {
        $user = $this->login->attemptCode($request, (string) $request->input('code', ''));

        if ($user === null) {
            return $this->failed();
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    private function failed(): RedirectResponse
    {
        return redirect()->route('emergency.login')
            ->withErrors(['emergency' => self::FAILURE_MESSAGE]);
    }
}
