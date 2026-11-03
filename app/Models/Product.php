<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A product card of a company's catalog: photo, name, short summary and an
 * optional https link (a datasheet, for example). Shown only when published.
 */
#[Fillable([
    'company_id',
    'product_category_id',
    'name',
    'summary',
    'photo_path',
    'link_url',
    'position',
    'published',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use LogsActivity;

    public const NAME_MAX = 120;

    public const SUMMARY_MAX = 280;

    public const LINK_MAX = 500;

    /** Photos live on the "public" disk under {PHOTO_DIR}/{company id}/. */
    public const PHOTO_DIR = 'products';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(ProductCategory::AUDIT_LOG)
            ->logOnly(['name', 'summary', 'photo_path', 'link_url', 'product_category_id', 'published', 'position'])
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
        activity(ProductCategory::AUDIT_LOG)
            ->performedOn($this)
            ->event($event)
            ->withProperties($properties)
            ->log($event);
    }

    protected function casts(): array
    {
        return ['published' => 'boolean', 'position' => 'integer'];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<ProductCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    /**
     * @param  Builder<Product>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('published', true);
    }

    /**
     * The public URL of the photo, or null when there is none.
     */
    public function photoUrl(): ?string
    {
        return $this->photo_path === null ? null : Storage::disk('public')->url($this->photo_path);
    }
}
