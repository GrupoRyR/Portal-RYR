<?php

namespace App\Models;

use Database\Factories\ItServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A service of one company that IT reports by hand in Administración ›
 * Estado de TI (correo, internet, VPN, telefonía...), next to the apps,
 * which report their own status. Read by App\ItStatus\ItStatusBoard.
 */
#[Fillable(['company_id', 'name', 'status', 'message', 'position'])]
class ItService extends Model
{
    /** @use HasFactory<ItServiceFactory> */
    use HasFactory;

    use LogsActivity;

    /** Activity log name for Estado de TI changes (spatie/laravel-activitylog). */
    public const AUDIT_LOG = 'it_services';

    public const NAME_MAX = 80;

    public const MESSAGE_MAX = 160;

    public const STATUS_OPERATIONAL = 'operational';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_DOWN = 'down';

    /** In order of severity, the healthy one first. */
    public const STATUSES = [self::STATUS_OPERATIONAL, self::STATUS_DEGRADED, self::STATUS_DOWN];

    private const STATUS_LABELS = [
        self::STATUS_OPERATIONAL => 'Operativo',
        self::STATUS_DEGRADED => 'Con fallas',
        self::STATUS_DOWN => 'Fuera de servicio',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::STATUS_OPERATIONAL,
        'position' => 0,
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(self::AUDIT_LOG)
            ->logOnly(['name', 'status', 'message', 'position', 'company_id'])
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

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /**
     * Spanish name of a status; an unknown value is returned as is.
     */
    public static function statusLabel(?string $status): string
    {
        return self::STATUS_LABELS[$status] ?? (string) $status;
    }

    /**
     * @return array<string, string> Every status with its Spanish name, in order.
     */
    public static function statusOptions(): array
    {
        return self::STATUS_LABELS;
    }

    public function isOperational(): bool
    {
        return $this->status === self::STATUS_OPERATIONAL;
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @param  Builder<ItService>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }
}
