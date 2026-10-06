<?php

namespace App\Auth;

use App\Audit\SecurityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Two-step local sign-in (password, then TOTP) for the emergency account,
 * used when Microsoft sign-in is unavailable. Every failure looks the same
 * to the caller; the reason only goes to the log and the security events
 * (App\Audit\SecurityLog), never secrets.
 */
class EmergencyLogin
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 60;

    public const PENDING_MINUTES = 5;

    public const SESSION_KEY = 'emergency.pending';

    public function __construct(private readonly EmergencyTwoFactor $twoFactor) {}

    /**
     * Step 1. On success the user is remembered in the session, pending the
     * code, for at most {@see PENDING_MINUTES} minutes.
     */
    public function attemptCredentials(Request $request, string $email, #[\SensitiveParameter] string $password): bool
    {
        $email = strtolower(trim($email));
        $key = 'emergency:credentials:'.sha1($email.'|'.$request->ip());

        if (! $this->hit($key)) {
            return $this->fail($request, $email, 'credentials', 'throttled');
        }

        $user = User::query()
            ->where('is_emergency', true)
            ->whereRaw('lower(email) = ?', [$email])
            ->first();

        // Hash anyway when there is no usable account, so timing does not
        // reveal which emails exist.
        $hash = $user?->password ?? Hash::make('emergency-timing-equaliser');
        $passwordOk = Hash::check($password, $hash);

        if ($user === null || ! self::isEligible($user) || ! $passwordOk) {
            $request->session()->forget(self::SESSION_KEY);

            return $this->fail($request, $email, 'credentials', 'invalid');
        }

        $request->session()->put(self::SESSION_KEY, [
            'user_id' => $user->id,
            'expires_at' => now()->addMinutes(self::PENDING_MINUTES)->getTimestamp(),
        ]);

        return true;
    }

    public function hasPendingStep(Request $request): bool
    {
        return $this->pendingUser($request) !== null;
    }

    /**
     * Step 2. Returns the user to sign in, or null.
     */
    public function attemptCode(Request $request, #[\SensitiveParameter] string $code): ?User
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            $request->session()->forget(self::SESSION_KEY);
            $this->fail($request, null, 'code', 'no_pending_step');

            return null;
        }

        // Throttled per pending account, so restarting step 1 in a new
        // session does not reset the counter.
        if (! $this->hit('emergency:code:'.$user->id)) {
            $this->fail($request, $user->email, 'code', 'throttled');

            return null;
        }

        $timestep = self::isEligible($user)
            ? $this->twoFactor->verify((string) $user->two_factor_secret, $code, $user->two_factor_last_timestep)
            : null;

        if ($timestep === null) {
            $this->fail($request, $user->email, 'code', 'invalid');

            return null;
        }

        $user->forceFill([
            'two_factor_last_timestep' => $timestep,
            'last_login_at' => now(),
        ])->save();

        $request->session()->forget(self::SESSION_KEY);

        Log::notice('Emergency sign-in succeeded.', $this->context($request, $user->email));
        SecurityLog::emergencySignIn($user, $request->userAgent());

        return $user;
    }

    /**
     * Only a local emergency account with a password and confirmed 2FA.
     * A Microsoft identity never qualifies, whatever else is set.
     */
    public static function isEligible(User $user): bool
    {
        return $user->is_emergency
            && $user->azure_oid === null
            && $user->tenant_id === null
            && $user->password !== null
            && filled($user->two_factor_secret)
            && $user->two_factor_confirmed_at !== null;
    }

    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get(self::SESSION_KEY);

        if (! is_array($pending) || ($pending['expires_at'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        return User::query()->where('is_emergency', true)->find($pending['user_id'] ?? null);
    }

    /**
     * Count the attempt; false once the limit is reached.
     */
    private function hit(string $key): bool
    {
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return false;
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        return true;
    }

    private function fail(Request $request, ?string $email, string $step, string $reason): false
    {
        Log::warning('Emergency sign-in failed.', $this->context($request, $email) + [
            'step' => $step,
            'reason' => $reason,
        ]);
        SecurityLog::emergencyFailed($email, $step, $reason);

        return false;
    }

    /**
     * @return array<string, string|null>
     */
    private function context(Request $request, ?string $email): array
    {
        return [
            'email' => $email,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];
    }
}
