<?php

namespace App\Models;

use Database\Factories\AppCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A group of apps on the home screen (Ventas, Tesorería...). Empty
 * categories are not shown.
 */
#[Fillable(['name', 'slug', 'sort_order'])]
class AppCategory extends Model
{
    /** @use HasFactory<AppCategoryFactory> */
    use HasFactory;

    use LogsActivity;

    /** Activity log name for category changes (spatie/laravel-activitylog). */
    public const AUDIT_LOG = 'categories';

    /**
     * Name and slug changes are logged with their causer. Order changes get
     * one `category.moved` entry instead of one entry per renumbered row.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(self::AUDIT_LOG)
            ->logOnly(['name', 'slug'])
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
        return ['sort_order' => 'integer'];
    }

    /**
     * @return HasMany<App, $this>
     */
    public function apps(): HasMany
    {
        return $this->hasMany(App::class);
    }

    /**
     * @param  Builder<AppCategory>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
