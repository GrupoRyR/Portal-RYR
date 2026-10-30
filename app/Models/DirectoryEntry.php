<?php

namespace App\Models;

use App\Catalog\AppSearch;
use Database\Factories\DirectoryEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person of a company's directory, copied from Microsoft Graph every
 * night (App\Directory\DirectorySync).
 *
 * @property int $id
 * @property int $company_id
 * @property string $tenant_id
 * @property string $azure_oid
 * @property string $display_name
 * @property string|null $job_title
 * @property string|null $department
 * @property string|null $office_location
 * @property string|null $business_phone
 * @property string $email
 * @property Carbon|null $hire_date
 * @property string $search_text
 * @property Carbon $synced_at
 */
#[Fillable([
    'company_id',
    'tenant_id',
    'azure_oid',
    'display_name',
    'job_title',
    'department',
    'office_location',
    'business_phone',
    'email',
    'hire_date',
    'synced_at',
])]
class DirectoryEntry extends Model
{
    /** @use HasFactory<DirectoryEntryFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * The search text follows the fields on every save; the copy writes
     * it itself, as an upsert fires no model events.
     */
    protected static function booted(): void
    {
        static::saving(function (self $entry) {
            $entry->search_text = self::searchTextFor($entry->only(self::SEARCHED));
        });
    }

    /** Fields the search looks through, in this order. */
    public const SEARCHED = ['display_name', 'job_title', 'department', 'office_location', 'email'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * Tenant IDs are GUIDs; Microsoft may send them in any case.
     */
    protected function tenantId(): Attribute
    {
        return Attribute::make(set: fn (string $value) => strtolower(trim($value)));
    }

    /**
     * Object IDs are GUIDs too.
     */
    protected function azureOid(): Attribute
    {
        return Attribute::make(set: fn (string $value) => strtolower(trim($value)));
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @param  array<string, string|null>  $fields
     */
    public static function searchTextFor(array $fields): string
    {
        return AppSearch::normalize(implode(' ', array_filter(array_map(fn ($value) => (string) $value, $fields))));
    }
}
