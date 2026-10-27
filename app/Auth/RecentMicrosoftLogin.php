<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * "Confirm it is really you" for sensitive company changes (creating a
 * company, changing a tenant): the signed-in user must have completed a
 * Microsoft sign-in within the last 10 minutes — either the normal sign-in
 * or a re-authentication with prompt=login as the same (tid, oid).
 *
 * prompt=login is only a request to Microsoft, so a re-authentication also
 * needs the id_token `auth_time` (an optional claim of the app registration)
 * to show the credentials were entered moments ago.
 */
class RecentMicrosoftLogin
{
    /** Unix timestamp of the last successful confirmation. */
    public const SESSION_KEY = 'auth.microsoft_confirmed_at';

    /** Confirmation in progress: ['return' => path, 'user' => id]. */
    public const PENDING_KEY = 'auth.microsoft_confirming';

    public const TTL_SECONDS = 600;

    /** Default of config('portal.reauth_max_age_seconds'). */
    public const DEFAULT_MAX_AUTH_AGE_SECONDS = 300;

    /** Tolerated clock difference with Microsoft for an `auth_time` ahead of us. */
    private const CLOCK_SKEW_SECONDS = 60;

    public const EMERGENCY_MESSAGE = 'Crear empresas o cambiar su inquilino requiere iniciar sesión con Microsoft.';

    public const UNAVAILABLE_MESSAGE = 'No pudimos confirmar tu identidad con Microsoft.';

    /** Outcomes of {@see verify()}. */
    public const CONFIRMED = 'confirmed';

    /** The same identity answered, but the id_token has no `auth_time`. */
    public const MISSING_AUTH_TIME = 'missing_auth_time';

    public const REJECTED = 'rejected';

    public function __construct(private readonly Session $session) {}

    public function isFresh(): bool
    {
        $confirmedAt = $this->session->get(self::SESSION_KEY);

        return is_int($confirmedAt) && now()->getTimestamp() - $confirmedAt < self::TTL_SECONDS;
    }

    public function start(User $user, string $return): void
    {
        $this->session->put(self::PENDING_KEY, ['return' => self::safeReturn($return), 'user' => $user->getKey()]);
    }

    /**
     * The pending confirmation for this user, consumed (one use only).
     *
     * @return array{return: string, user: int}|null
     */
    public function pull(User $user): ?array
    {
        $pending = $this->session->pull(self::PENDING_KEY);

        if (! is_array($pending) || ($pending['user'] ?? null) !== $user->getKey()) {
            return null;
        }

        return ['return' => self::safeReturn((string) ($pending['return'] ?? '/')), 'user' => $user->getKey()];
    }

    /**
     * Only the same Microsoft identity the user signed in with may confirm,
     * and only with credentials entered just now. A missing `auth_time` from
     * that same identity means the optional claim is not configured on the
     * app registration: {@see MISSING_AUTH_TIME}, never a confirmation.
     * Another identity, or a stale or future `auth_time`, is {@see REJECTED}.
     *
     * @return self::CONFIRMED|self::MISSING_AUTH_TIME|self::REJECTED
     */
    public function verify(User $user, MicrosoftIdentity $identity): string
    {
        if (! $this->sameIdentity($user, $identity)) {
            return self::REJECTED;
        }

        if ($identity->authTime === null) {
            return self::MISSING_AUTH_TIME;
        }

        return $this->authenticatedRecently($identity->authTime) ? self::CONFIRMED : self::REJECTED;
    }

    private function sameIdentity(User $user, MicrosoftIdentity $identity): bool
    {
        return ! $user->is_emergency
            && $user->tenant_id !== null
            && $user->azure_oid !== null
            && hash_equals($user->tenant_id, strtolower($identity->tenantId))
            && hash_equals($user->azure_oid, strtolower($identity->objectId));
    }

    /**
     * Whether `auth_time` falls within config('portal.reauth_max_age_seconds')
     * of now.
     */
    private function authenticatedRecently(int $authTime): bool
    {
        $maxAge = (int) config('portal.reauth_max_age_seconds', self::DEFAULT_MAX_AUTH_AGE_SECONDS);
        $age = now()->getTimestamp() - $authTime;

        return $age <= $maxAge && $age >= -self::CLOCK_SKEW_SECONDS;
    }

    public function confirm(): void
    {
        $this->session->put(self::SESSION_KEY, now()->getTimestamp());
    }

    /**
     * A same-site relative path ("/admin/empresas?x=1"), or "/" for anything
     * that could leave the site: absolute or protocol-relative URLs,
     * backslashes (browsers treat "/\" like "//"), control characters.
     */
    public static function safeReturn(string $return): string
    {
        if (! str_starts_with($return, '/')
            || str_starts_with($return, '//')
            || str_contains($return, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $return) === 1
            || strlen($return) > 2000) {
            return '/';
        }

        return $return;
    }
}
