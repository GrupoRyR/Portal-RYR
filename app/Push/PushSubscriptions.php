<?php

namespace App\Push;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

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

    /**
     * The devices that may get notices at all, with the users table joined
     * (select `push_subscriptions.*`): of accounts that are not the
     * emergency one, whose company is active, and that signed in within
     * config push.max_inactive_days. Which announcement each one sees is
     * App\Push\AnnouncementPushes' job.
     *
     * @return Builder<PushSubscription>
     */
    public static function reachable(): Builder
    {
        return PushSubscription::query()
            ->join('users', 'users.id', '=', 'push_subscriptions.user_id')
            ->join('companies', 'companies.id', '=', 'users.company_id')
            ->where('companies.is_active', true)
            ->where('users.is_emergency', false)
            ->where('users.last_login_at', '>=', now()->subDays((int) config('push.max_inactive_days', 90)));
    }

    /**
     * Daily (portal:prune-push-subscriptions): deletes every device that is
     * not {@see reachable()}, so a phone of an account that stopped signing
     * in, or of a company that was switched off, is forgotten. Returns how
     * many went.
     */
    public function prune(): int
    {
        // The ids first: MariaDB refuses a DELETE with a subquery on the same table.
        $ids = PushSubscription::query()
            ->leftJoin('users', 'users.id', '=', 'push_subscriptions.user_id')
            ->leftJoin('companies', 'companies.id', '=', 'users.company_id')
            ->where(fn (Builder $query) => $query
                ->whereNull('companies.id')
                ->orWhere('companies.is_active', false)
                ->orWhere('users.is_emergency', true)
                ->orWhereNull('users.last_login_at')
                ->orWhere('users.last_login_at', '<', now()->subDays((int) config('push.max_inactive_days', 90))))
            ->pluck('push_subscriptions.id');

        $deleted = 0;

        foreach ($ids->chunk(500) as $chunk) {
            $deleted += PushSubscription::query()->whereKey($chunk->all())->delete();
        }

        return $deleted;
    }

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
