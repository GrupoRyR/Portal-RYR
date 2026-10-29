<?php

namespace App\Models;

use Database\Factories\IntegrationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use LogicException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * An external system of the API (brief §6b): a monitor or IT tool that
 * reads the catalog or reports app statuses with its own Bearer token,
 * managed in Administración → Integraciones. The tasks app publishes its
 * work items (`work:write`, docs/contrato-tareas-aprobaciones.md): it
 * also needs its catalog app (`app_id`, whose host is the only one allowed
 * in the links) and its base URL (`app_base_url`).
 *
 * The integration owns the Sanctum token (never a user). It has at most one
 * live token: rotating replaces it at once, revoking deletes it for good.
 * The plaintext token is returned once by issueToken()/rotate() and never
 * stored nor logged: only its SHA-256 hash (Sanctum) and its last four
 * characters (`token_hint`, to recognise it in the list).
 */
#[Fillable(['name', 'abilities', 'allowed_ips', 'app_id', 'app_base_url', 'created_by'])]
class Integration extends Model
{
    use HasApiTokens;

    /** @use HasFactory<IntegrationFactory> */
    use HasFactory;

    /**
     * Activity log of everything done through or about the API: status
     * reports (App\Api\StatusReporter) and token management. Shown in
     * Auditoría under «API». Never holds a token.
     */
    public const AUDIT_LOG = 'api';

    public const CATALOG_READ = 'catalog:read';

    public const STATUS_WRITE = 'status:write';

    public const WORK_WRITE = 'work:write';

    /** The only abilities a token may have, with their description. */
    public const ABILITIES = [
        self::CATALOG_READ => 'Leer el catálogo de apps, sin usuarios ni reglas',
        self::STATUS_WRITE => 'Reportar si una app funciona, falla o está caída',
        self::WORK_WRITE => 'Publicar tareas y aprobaciones en el inicio de cada persona',
    ];

    /** Rate limit of each token (named limiter `api`). */
    public const REQUESTS_PER_MINUTE = 120;

    /** The last use is written at most once per this many seconds. */
    public const LAST_USED_PRECISION = 60;

    public const MAX_NAME = 80;

    public const MAX_IPS = 20;

    public const MAX_APP_BASE_URL = 255;

    protected function casts(): array
    {
        return [
            'allowed_ips' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Known abilities only, without duplicates, in the order of ABILITIES.
     */
    protected function abilities(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => self::knownAbilities(json_decode((string) $value, true) ?: []),
            set: fn (mixed $value) => json_encode(self::knownAbilities((array) $value)),
        );
    }

    /**
     * @param  array<int, mixed>  $abilities
     * @return list<string>
     */
    public static function knownAbilities(array $abilities): array
    {
        return array_values(array_filter(array_keys(self::ABILITIES), fn (string $ability) => in_array($ability, $abilities, true)));
    }

    /**
     * The live token, if any.
     *
     * @return MorphOne<PersonalAccessToken, $this>
     */
    public function token(): MorphOne
    {
        return $this->morphOne(PersonalAccessToken::class, 'tokenable')->latestOfMany();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The catalog app of a `work:write` integration: its host is the only
     * one allowed in the links of the work items.
     *
     * @return BelongsTo<App, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    /**
     * Tasks and approvals this system published, deletion traces included.
     *
     * @return HasMany<WorkItem, $this>
     */
    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItem::class);
    }

    /**
     * Status changes this system reported.
     *
     * @return HasMany<AppStatusEvent, $this>
     */
    public function statusEvents(): HasMany
    {
        return $this->hasMany(AppStatusEvent::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Replaces any token of the integration with a new one carrying its
     * abilities and returns the plaintext (`ryr_` + 48 characters), which
     * is shown once and must never be stored or logged.
     */
    public function issueToken(): string
    {
        if ($this->isRevoked()) {
            throw new LogicException('A revoked integration cannot get a new token.');
        }

        return DB::transaction(function () {
            $this->tokens()->delete();

            // Sanctum returns "{id}|{secret}"; the secret alone is unique
            // and found by its hash, so the recognisable prefix comes first.
            [, $plain] = explode('|', $this->createToken($this->name, $this->abilities)->plainTextToken, 2);

            $this->forceFill(['token_hint' => substr($plain, -4)])->save();

            return $plain;
        });
    }

    /**
     * New secret; the previous token stops working immediately.
     */
    public function rotate(): string
    {
        return $this->issueToken();
    }

    /**
     * Deletes the token for good, and the work items it published, which
     * leave the home at once (contract §2.2). A revoked integration stays
     * listed.
     */
    public function revoke(): void
    {
        DB::transaction(function () {
            $this->tokens()->delete();
            $this->workItems()->withTrashed()->forceDelete();
            $this->forceFill(['revoked_at' => now()])->save();
        });
    }

    /**
     * New abilities for the integration and its live token, effective on
     * the next request. Returns whether anything changed.
     *
     * @param  list<string>  $abilities
     */
    public function changeAbilities(array $abilities): bool
    {
        $abilities = self::knownAbilities($abilities);

        if ($abilities === $this->abilities) {
            return false;
        }

        DB::transaction(function () use ($abilities) {
            $this->abilities = $abilities;
            $this->save();
            $this->tokens()->update(['abilities' => json_encode($abilities)]);
        });

        return true;
    }

    /**
     * Writes a named audit entry about this integration (log `api`); the
     * causer is the signed-in admin, if any.
     *
     * @param  array<string, mixed>  $properties
     */
    public function audit(string $event, array $properties = []): void
    {
        activity(self::AUDIT_LOG)
            ->performedOn($this)
            ->event($event)
            ->withProperties($properties)
            ->log($event);
    }

    /**
     * «ryr_…9c2f»: enough to recognise a token, useless to use it.
     */
    public function maskedToken(): ?string
    {
        return $this->token_hint === null ? null : config('sanctum.token_prefix').'…'.$this->token_hint;
    }

    /**
     * An empty list accepts any address; entries are IPs or CIDR ranges.
     */
    public function allowsIp(?string $ip): bool
    {
        $allowed = $this->allowed_ips ?? [];

        return $allowed === [] || ($ip !== null && IpUtils::checkIp($ip, $allowed));
    }

    /**
     * An IPv4/IPv6 address, or a CIDR range such as 192.168.1.0/24.
     */
    public static function isValidIpEntry(string $entry): bool
    {
        [$ip, $bits] = array_pad(explode('/', trim($entry), 2), 2, null);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($bits === null) {
            return true;
        }

        $max = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 32 : 128;

        return ctype_digit($bits) && (int) $bits <= $max;
    }

    /**
     * A base URL the portal may call (contract §2.2): `https`, a host, no
     * user info, no `?` nor `#`, printable ASCII only.
     */
    public static function isValidAppBaseUrl(string $url): bool
    {
        if (strlen($url) > self::MAX_APP_BASE_URL || str_contains($url, '\\')
            || preg_match('~\A[\x21-\x7E]+\z~', $url) !== 1
            || preg_match('~\Ahttps://[^/?#@]+(?:/[^?#]*)?\z~i', $url) !== 1) {
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false && is_string(parse_url($url, PHP_URL_HOST));
    }

    /**
     * Records that the token was used, at most once a minute, without
     * touching `updated_at`.
     */
    public function recordUse(): void
    {
        if ($this->last_used_at !== null && $this->last_used_at->gt(now()->subSeconds(self::LAST_USED_PRECISION))) {
            return;
        }

        $now = now();
        static::query()->whereKey($this->getKey())->toBase()->update(['last_used_at' => $now]);
        $this->last_used_at = $now;
        $this->syncOriginalAttribute('last_used_at');
    }
}
