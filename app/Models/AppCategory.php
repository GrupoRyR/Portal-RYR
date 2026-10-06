<?php

namespace App\Models;

use Database\Factories\AppCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group of apps on the home screen (Ventas, Tesorería...). Empty
 * categories are not shown.
 */
#[Fillable(['name', 'slug', 'sort_order'])]
class AppCategory extends Model
{
    /** @use HasFactory<AppCategoryFactory> */
    use HasFactory;

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
