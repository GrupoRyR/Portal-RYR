<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A company of the group. Each one is bound to a Microsoft tenant; the
 * tenant of a signed-in user decides which company (and brand) they get.
 */
#[Fillable([
    'name',
    'short_name',
    'slug',
    'tenant_id',
    'is_primary_for_tenant',
    'domains',
    'logo_light_path',
    'logo_dark_path',
    'icon_path',
    'color_primary',
    'color_accent',
    'color_dark',
    'quick_links',
    'is_active',
    'consent_verified_at',
])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    /**
     * Defaults mirrored from the migration so new instances behave the same
     * before and after being persisted.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_primary_for_tenant' => true,
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'domains' => 'array',
            'quick_links' => 'array',
            'is_primary_for_tenant' => 'boolean',
            'is_active' => 'boolean',
            'consent_verified_at' => 'datetime',
        ];
    }

    /**
     * Tenant IDs are GUIDs; Microsoft may send them in any case.
     */
    protected function tenantId(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value === null ? null : strtolower(trim($value)),
        );
    }

    /**
     * Email domains are stored lowercase, without a leading "@", unique.
     */
    protected function domains(): Attribute
    {
        return Attribute::make(
            set: fn (?array $value) => json_encode(
                collect($value ?? [])
                    ->map(fn ($domain) => ltrim(strtolower(trim((string) $domain)), '@'))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all()
            ),
        );
    }

    /**
     * Scope a query to companies whose users may sign in.
     *
     * @param  Builder<Company>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Whether the email belongs to one of this company's domains. The match
     * is exact on the part after the last "@" (no subdomains, no suffixes).
     */
    public function ownsEmailDomain(string $email): bool
    {
        $email = strtolower(trim($email));
        $at = strrpos($email, '@');

        if ($at === false) {
            return false;
        }

        $domain = substr($email, $at + 1);

        return $domain !== '' && in_array($domain, $this->domains ?? [], true);
    }
}
