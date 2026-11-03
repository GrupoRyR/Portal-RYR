<?php

namespace App\Push;

use App\Models\PushSubscription;
use App\Models\User;

/**
 * The devices of an account that get «Avisos en el celular» (POST and
 * DELETE /cuenta/avisos, App\Http\Controllers\Account\PushSubscriptionController).
 *
 * An endpoint is one browser on one device, so it belongs to one account:
 * subscribing it again (another account signed in on that browser) moves
 * it to the account that sent it. An account keeps its MAX_PER_USER newest
 * devices. The cron only ever posts to endpoints at the push services of
 * config/push.php `allowed_hosts` ({@see allowsEndpoint()}).
 */
class PushSubscriptions
{
    /** Devices per account; the oldest go first. */
    public const MAX_PER_USER = 10;

    public const MAX_ENDPOINT_LENGTH = 2048;

    public function subscribe(User $user, string $endpoint, string $publicKey, string $authToken, string $contentEncoding, ?string $userAgent): void
    {
        $now = now();

        // One statement, so two posts of the same device at once leave one row.
        PushSubscription::query()->upsert([[
            'user_id' => $user->getKey(),
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashOf($endpoint),
            'public_key' => $publicKey,
            'auth_token' => $authToken,
            'content_encoding' => $contentEncoding,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['endpoint_hash'], ['user_id', 'endpoint', 'public_key', 'auth_token', 'content_encoding', 'user_agent', 'updated_at']);

        $keep = PushSubscription::query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::MAX_PER_USER)
            ->pluck('id');

        PushSubscription::query()->where('user_id', $user->getKey())->whereKeyNot($keep->all())->delete();
    }

    /**
     * Only the account's own device: knowing another account's endpoint
     * does not remove it.
     */
    public function unsubscribe(User $user, string $endpoint): void
    {
        PushSubscription::query()
            ->where('user_id', $user->getKey())
            ->where('endpoint_hash', PushSubscription::hashOf($endpoint))
            ->delete();
    }

    /**
     * An https URL on the default port, without credentials, at one of the
     * allowed push services (the host itself or a subdomain of it).
     */
    public static function allowsEndpoint(string $endpoint): bool
    {
        if (strlen($endpoint) > self::MAX_ENDPOINT_LENGTH || filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($endpoint);

        if (! is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || ! isset($parts['host'])) {
            return false;
        }

        $host = strtolower($parts['host']);

        foreach ((array) config('push.allowed_hosts', []) as $allowed) {
            $allowed = strtolower(trim((string) $allowed, ". \t\n\r\0\x0B"));

            if ($allowed !== '' && ($host === $allowed || str_ends_with($host, '.'.$allowed))) {
                return true;
            }
        }

        return false;
    }
}
