<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * "Confirm it is really you" for sensitive company changes (creating a
 * company, changing a tenant): the signed-in user must have completed a
 * Microsoft sign-in with prompt=login, as the same (tid, oid), within the
 * last 10 minutes.
 */
class RecentMicrosoftLogin
{
    /** Unix timestamp of the last successful confirmation. */
    public const SESSION_KEY = 'auth.microsoft_confirmed_at';

    /** Confirmation in progress: ['return' => path, 'user' => id]. */
    public const PENDING_KEY = 'auth.microsoft_confirming';

    public const TTL_SECONDS = 600;

    public const EMERGENCY_MESSAGE = 'Crear empresas o cambiar su inquilino requiere iniciar sesión con Microsoft.';

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
     * Only the same Microsoft identity the user signed in with may confirm.
     */
    public function matches(User $user, MicrosoftIdentity $identity): bool
    {
        return ! $user->is_emergency
            && $user->tenant_id !== null
            && $user->azure_oid !== null
            && hash_equals($user->tenant_id, strtolower($identity->tenantId))
            && hash_equals($user->azure_oid, strtolower($identity->objectId));
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
