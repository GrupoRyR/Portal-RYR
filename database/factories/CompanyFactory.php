<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $shortName = fake()->unique()->company();
        $slug = Str::slug($shortName);

        return [
            'name' => $shortName.' S.A.S.',
            'short_name' => $shortName,
            'slug' => $slug,
            'tenant_id' => (string) Str::uuid(),
            'is_primary_for_tenant' => true,
            'domains' => [$slug.'.com'],
            'logo_light_path' => null,
            'logo_dark_path' => null,
            'icon_path' => null,
            'color_primary' => fake()->hexColor(),
            'color_accent' => fake()->hexColor(),
            'color_dark' => fake()->hexColor(),
            'quick_links' => null,
            'is_active' => true,
            'consent_verified_at' => null,
        ];
    }

    /**
     * A company whose users may not sign in.
     */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * A company sharing the given Microsoft tenant.
     *
     * @param  list<string>  $domains
     */
    public function forTenant(string $tenantId, array $domains = [], bool $primary = true): static
    {
        $state = ['tenant_id' => $tenantId, 'is_primary_for_tenant' => $primary];

        if ($domains !== []) {
            $state['domains'] = $domains;
        }

        return $this->state(fn () => $state);
    }
}
