<?php

namespace App\Settings;

use App\Catalog\AppUrlPolicy;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Portal-wide settings managed in Administración → Configuración
 * (docs/diseno/AdminSettings.dc.html).
 *
 * Saved values live in the `portal_settings` table (one row per key, the
 * value JSON-encoded) and are cached forever until the next save. A key
 * that was never saved falls back to config/portal.php (and the session
 * lifetime), so nothing changes until a super admin saves. Every change is
 * written to the activity log, shown in Auditoría as «Cambio».
 */
final class PortalSettings
{
    /** Activity log name of the changes. */
    public const AUDIT_LOG = 'settings';

    public const CACHE_KEY = 'portal.settings';

    public const SUPPORT_URL = 'support_url';

    public const IDLE_TIMEOUT = 'idle_timeout_minutes';

    public const MICROSOFT_LOGOUT = 'microsoft_logout';

    public const ALLOWED_APP_HOSTS = 'allowed_app_hosts';

    public const API_STATUS_TTL = 'api_status_ttl_hours';

    public const KEYS = [
        self::SUPPORT_URL,
        self::IDLE_TIMEOUT,
        self::MICROSOFT_LOGOUT,
        self::ALLOWED_APP_HOSTS,
        self::API_STATUS_TTL,
    ];

    /** Idle timeout choices, in minutes (4, 8 and 24 hours). */
    public const IDLE_TIMEOUT_CHOICES = [240, 480, 1440];

    /** «Con fallas» expiry choices, in hours. */
    public const API_STATUS_TTL_CHOICES = [1, 2, 4];

    public const SUPPORT_URL_MAX = 500;

    /** @var array<string, mixed>|null Saved values, keyed by setting. */
    private ?array $stored = null;

    public function supportUrl(): ?string
    {
        return $this->get(self::SUPPORT_URL);
    }

    public function idleTimeoutMinutes(): int
    {
        return $this->get(self::IDLE_TIMEOUT);
    }

    public function signsOutOfMicrosoft(): bool
    {
        return $this->get(self::MICROSOFT_LOGOUT);
    }

    /**
     * @return list<string>
     */
    public function allowedAppHosts(): array
    {
        return $this->get(self::ALLOWED_APP_HOSTS);
    }

    public function apiStatusTtlHours(): int
    {
        return $this->get(self::API_STATUS_TTL);
    }

    /**
     * Effective value of every setting.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $values = [];
        foreach (self::KEYS as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    /**
     * Saves the given settings (unknown keys are ignored) and logs the ones
     * that changed, with their before and after. Returns the changed keys.
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public function update(array $values, ?User $causer): array
    {
        $old = [];
        $new = [];

        foreach (self::KEYS as $key) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $value = self::normalize($key, $values[$key]);
            $current = $this->get($key);

            if ($key === self::ALLOWED_APP_HOSTS && array_filter($value, fn (string $host) => ! AppUrlPolicy::isValidPattern($host)) !== []) {
                throw new InvalidArgumentException('Every allowed host must be a valid host pattern.');
            }

            if ($value !== $current) {
                $old[$key] = $current;
                $new[$key] = $value;
            }
        }

        if ($new === []) {
            return [];
        }

        DB::transaction(function () use ($old, $new, $causer) {
            foreach ($new as $key => $value) {
                DB::table('portal_settings')->updateOrInsert(
                    ['key' => $key],
                    ['value' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'updated_at' => now()],
                );
            }

            $logger = activity(self::AUDIT_LOG)
                ->event('settings.updated')
                ->withProperties(['old' => $old, 'attributes' => $new]);

            if ($causer !== null) {
                $logger->causedBy($causer);
            }

            $logger->log('settings.updated');
        });

        Cache::forget(self::CACHE_KEY);
        $this->stored = null;

        return array_keys($new);
    }

    private function get(string $key): mixed
    {
        $stored = $this->stored();

        return array_key_exists($key, $stored)
            ? self::normalize($key, $stored[$key])
            : self::normalize($key, $this->default($key));
    }

    /**
     * Defaults are read on every call so that config changes (tests, the
     * session lifetime) are always honoured.
     */
    private function default(string $key): mixed
    {
        return match ($key) {
            self::SUPPORT_URL => config('portal.support_url'),
            self::IDLE_TIMEOUT => config('session.lifetime'),
            self::MICROSOFT_LOGOUT => config('portal.microsoft_logout', false),
            self::ALLOWED_APP_HOSTS => config('portal.allowed_app_hosts', []),
            self::API_STATUS_TTL => config('portal.api_status_ttl_hours', 2),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        return $this->stored ??= Cache::rememberForever(self::CACHE_KEY, fn () => DB::table('portal_settings')
            ->pluck('value', 'key')
            ->map(fn (string $value) => json_decode($value, true))
            ->all());
    }

    /**
     * Casts a value to the type of its setting. The admin form validates
     * first; this is the last line of defence.
     */
    private static function normalize(string $key, mixed $value): mixed
    {
        return match ($key) {
            self::SUPPORT_URL => self::supportUrlValue($value),
            self::IDLE_TIMEOUT, self::API_STATUS_TTL => max(1, (int) $value),
            self::MICROSOFT_LOGOUT => (bool) $value,
            self::ALLOWED_APP_HOSTS => self::hostsValue($value),
        };
    }

    private static function supportUrlValue(mixed $value): ?string
    {
        $url = trim((string) $value);

        if ($url === '') {
            return null;
        }

        if (! str_starts_with(strtolower($url), 'https://')) {
            throw new InvalidArgumentException('The support URL must use https://.');
        }

        return $url;
    }

    /**
     * @return list<string>
     */
    private static function hostsValue(mixed $value): array
    {
        $hosts = [];
        foreach ((array) $value as $host) {
            $host = strtolower(trim((string) $host));

            if ($host !== '' && ! in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }
}
