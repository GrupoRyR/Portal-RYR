<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;

/**
 * A Microsoft round trip a signed-in user starts for something other than
 * a re-authentication: linking another company's account, or switching to
 * it. The shared callback gets it back once only, for the same user, with
 * the OAuth `state` of its own redirect, and within {@see TTL_SECONDS}.
 *
 * An intent never grants a session: each flow still checks the identity
 * Microsoft answers with.
 */
class AccountIntent
{
    /** ['type', 'user_id', 'target_id', 'attempt', 'state', 'expires_at']. */
    public const SESSION_KEY = 'auth.account_intent';

    public const LINK = 'link';

    public const SWITCH = 'switch';

    public const TTL_SECONDS = 600;

    public const UNUSABLE_MESSAGE = 'La solicitud ya no es válida. Inténtalo de nuevo.';

    /** Where Socialite keeps the OAuth `state` between redirect() and user(). */
    private const SOCIALITE_STATE_KEY = 'state';

    public function __construct(private readonly Session $session) {}

    /**
     * Only one Microsoft round trip is pending at a time, so a pending
     * re-authentication and any older intent are dropped. Call
     * {@see bindState()} once Socialite's redirect() is built.
     */
    public function start(string $type, User $user, ?User $target = null, int $attempt = 1): void
    {
        $this->session->forget([RecentMicrosoftLogin::PENDING_KEY, self::SESSION_KEY]);

        $this->session->put(self::SESSION_KEY, [
            'type' => $type,
            'user_id' => $user->getKey(),
            'target_id' => $target?->getKey(),
            'attempt' => $attempt,
            'state' => null,
            'expires_at' => now()->getTimestamp() + self::TTL_SECONDS,
        ]);
    }

    /**
     * Ties the pending intent to the `state` Socialite's redirect() just
     * stored: only the callback of that redirect can use it. Until then it
     * matches no callback.
     */
    public function bindState(): void
    {
        $state = $this->session->get(self::SOCIALITE_STATE_KEY);

        if (is_array($this->session->get(self::SESSION_KEY)) && is_string($state) && $state !== '') {
            $this->session->put(self::SESSION_KEY.'.state', $state);
        }
    }

    public function pending(): bool
    {
        return $this->session->has(self::SESSION_KEY);
    }

    public function discard(): void
    {
        $this->session->forget(self::SESSION_KEY);
    }

    /**
     * The pending intent, consumed (one use only), when it was bound to this
     * callback's `state`, belongs to this user, has a known type and has not
     * expired; otherwise null.
     *
     * @return array{type: string, user_id: int, target_id: int|null, attempt: int, state: string, expires_at: int}|null
     */
    public function pull(Request $request, User $user): ?array
    {
        $intent = $this->session->pull(self::SESSION_KEY);
        $state = $request->query('state');

        if (! is_array($intent)
            || ! is_string($intent['state'] ?? null)
            || ! is_string($state)
            || ! hash_equals($intent['state'], $state)
            || ($intent['user_id'] ?? null) !== $user->getKey()
            || ! in_array($intent['type'] ?? null, [self::LINK, self::SWITCH], true)
            || ! is_int($intent['expires_at'] ?? null)
            || now()->getTimestamp() >= $intent['expires_at']) {
            return null;
        }

        return $intent;
    }
}
