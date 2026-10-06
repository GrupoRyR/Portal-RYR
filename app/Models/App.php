<?php

namespace App\Models;

use App\Catalog\AppUrlPolicy;
use Database\Factories\AppFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use LogicException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * An application of the catalog. `web` apps open their URL; `local` apps
 * are installed on the computer and show an info page instead.
 *
 * The `slug` is the fixed identifier used by the API and Soporte TI: it is
 * set once at creation and never changes. The `external_status*` columns
 * are written by external systems (Phase 8) and only read here.
 */
#[Fillable([
    'slug',
    'name',
    'description',
    'icon_path',
    'icon_dark_path',
    'accent_color',
    'url',
    'type',
    'open_in',
    'hosting',
    'login_type',
    'app_category_id',
    'available_to_all_companies',
    'sort_order',
    'status',
    'maintenance_message',
    'maintenance_until',
    'local_instructions',
    'download_url',
])]
class App extends Model
{
    /** @use HasFactory<AppFactory> */
    use HasFactory;

    use LogsActivity;

    /** Activity log name for catalog app changes (spatie/laravel-activitylog). */
    public const AUDIT_LOG = 'apps';

    /**
     * Uploaded icons live on the "public" disk under {ICON_DIR}/{slug}/;
     * the bundled ones (seeders) are files in public/{ICON_DIR}/.
     */
    public const ICON_DIR = 'icons/apps';

    public const TYPE_WEB = 'web';

    public const TYPE_LOCAL = 'local';

    public const TYPES = [self::TYPE_WEB, self::TYPE_LOCAL];

    public const OPEN_SAME = 'same';

    public const OPEN_NEW = 'new';

    public const OPEN_IN = [self::OPEN_SAME, self::OPEN_NEW];

    public const HOSTINGS = ['godaddy', 'vercel', 'local', 'other'];

    public const LOGIN_TYPES = ['microsoft', 'own', 'local'];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_MAINTENANCE = 'maintenance';

    public const STATUS_HIDDEN = 'hidden';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_MAINTENANCE, self::STATUS_HIDDEN];

    public const EXTERNAL_STATUSES = ['operational', 'degraded', 'down'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'sort_order' => 0,
        'available_to_all_companies' => false,
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'available_to_all_companies' => 'boolean',
            'maintenance_until' => 'datetime',
            'external_status_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $app) {
            $app->guardUrl();
        });

        static::updating(function (self $app) {
            if ($app->isDirty('slug')) {
                throw new LogicException('The slug of an app cannot change after creation.');
            }
        });

        static::updated(function (self $app) {
            if ($app->wasChanged('status')) {
                $app->audit('app.status_changed', [
                    'old' => ['status' => $app->getOriginal('status')],
                    'attributes' => $app->only(['status', 'maintenance_message', 'maintenance_until']),
                ]);
            }
        });
    }

    /**
     * Every change to a fillable attribute is logged with its causer and the
     * before/after values of the changed fields only. Apps hold no secrets.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(self::AUDIT_LOG)
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Writes a named audit entry; the causer is the signed-in user, if any.
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
     * @return BelongsTo<AppCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AppCategory::class, 'app_category_id');
    }

    /**
     * Companies whose users may get this app (rules still apply). Ignored
     * when `available_to_all_companies` is on: then every active company
     * may get it.
     *
     * @return BelongsToMany<Company, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'app_companies')->withTimestamps();
    }

    /**
     * @return HasMany<AppAccessRule, $this>
     */
    public function accessRules(): HasMany
    {
        return $this->hasMany(AppAccessRule::class);
    }

    public function isWeb(): bool
    {
        return $this->type === self::TYPE_WEB;
    }

    public function isLocal(): bool
    {
        return $this->type === self::TYPE_LOCAL;
    }

    public function inMaintenance(): bool
    {
        return $this->status === self::STATUS_MAINTENANCE;
    }

    /**
     * Public URL of the light (or dark) icon, uploaded or bundled.
     */
    public function iconUrl(bool $dark = false): ?string
    {
        $path = $dark ? $this->icon_dark_path : $this->icon_path;

        if ($path === null) {
            return null;
        }

        return $this->isUploadedIcon($path) ? Storage::disk('public')->url($path) : asset($path);
    }

    /**
     * Whether the path is an upload of this app (as opposed to a bundled
     * file under public/), so it may be deleted when replaced.
     */
    public function isUploadedIcon(?string $path): bool
    {
        return $path !== null && str_starts_with($path, self::ICON_DIR.'/'.$this->slug.'/');
    }

    /**
     * Whether a web app may point at the URL (allowed hosts of Configuración).
     */
    public static function urlIsAllowed(?string $url): bool
    {
        return AppUrlPolicy::current()->allows($url);
    }

    /**
     * Last line of defence behind the admin form: web apps need an allowed
     * URL and local apps have none.
     */
    private function guardUrl(): void
    {
        if ($this->isLocal()) {
            if ($this->url !== null) {
                throw new InvalidArgumentException('A local app has no URL.');
            }

            return;
        }

        if (! self::urlIsAllowed($this->url)) {
            throw new InvalidArgumentException('A web app needs an https:// URL on an allowed host.');
        }
    }
}
