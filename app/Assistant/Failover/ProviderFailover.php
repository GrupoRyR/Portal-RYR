<?php

namespace App\Assistant\Failover;

use App\Assistant\AssistantLog;
use App\Assistant\AssistantSettings;
use App\Assistant\Budget\AdminRecipients;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * When the primary provider fails, the assistant moves to the secondary
 * (`assistant.secondary_model`, ASSISTANT_SECONDARY_MODEL). Errors are
 * classified by TYPE (App\Assistant\Model\ModelUnavailable::$reason, from
 * the SDK's error type or the HTTP status), never by their text, and only
 * after the driver's own retries:
 *
 * - LONG (billing, auth, a missing key): the primary is marked down for
 *   `assistant.primary_cooldown_minutes` (30), and the super admins get
 *   one email per window;
 * - SHORT (rate limits, overload, 5xx, timeouts, connection): down for
 *   `assistant.short_cooldown_minutes` (2);
 * - anything else (400, 404, 413, a prompt too long...) is a bug: no
 *   failover, logged, and the page shows the error.
 *
 * The circuit breaker lives in the cache (the database store in
 * production), per provider, so while it is open every new conversation
 * starts on the secondary without paying a failed call first. The monthly
 * budget is global and never fails over (it is checked before any call).
 */
class ProviderFailover
{
    public const LONG = 'long';

    public const SHORT = 'short';

    private const LONG_REASONS = ['billing', 'auth', 'not_configured'];

    private const SHORT_REASONS = ['rate_limited', 'overloaded', 'timeout', 'connection'];

    public function __construct(private readonly AssistantSettings $settings) {}

    /**
     * LONG, SHORT, or null when the error must not fail over.
     */
    public static function kind(?string $reason): ?string
    {
        return match (true) {
            in_array($reason, self::LONG_REASONS, true) => self::LONG,
            in_array($reason, self::SHORT_REASONS, true), is_string($reason) && preg_match('/^http_5\d\d$/', $reason) === 1 => self::SHORT,
            default => null,
        };
    }

    public function isDown(string $provider): bool
    {
        return Cache::has(self::key($provider));
    }

    /**
     * Opens the breaker for the provider and, for a LONG failure, emails
     * the super admins once per cooldown window.
     */
    public function markDown(string $provider, string $reason): void
    {
        $kind = self::kind($reason);

        if ($kind === null) {
            return;
        }

        $minutes = $kind === self::LONG ? $this->settings->primaryCooldownMinutes : $this->settings->shortCooldownMinutes;
        Cache::put(self::key($provider), $reason, now()->addMinutes($minutes));

        if ($kind === self::LONG && Cache::add(self::key($provider).':alert', true, now()->addMinutes($minutes))) {
            $this->alert($provider, $reason);
        }
    }

    private function alert(string $provider, string $reason): void
    {
        $recipients = AdminRecipients::superAdmins();
        AssistantLog::primaryDownAlert($provider, $reason, $recipients->count());

        DB::afterCommit(function () use ($provider, $reason, $recipients) {
            $notice = new PrimaryDownNotice($provider, $reason);

            foreach ($recipients as $recipient) {
                try {
                    Notification::sendNow($recipient, $notice);
                } catch (Throwable $e) {
                    Log::warning('Assistant failover alert could not be sent.', ['provider' => $provider, 'exception' => $e::class]);
                }
            }
        });
    }

    private static function key(string $provider): string
    {
        return 'assistant:provider-down:'.$provider;
    }
}
