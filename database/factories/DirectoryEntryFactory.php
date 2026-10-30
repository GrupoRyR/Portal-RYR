<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\DirectoryEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DirectoryEntry>
 */
class DirectoryEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->name();

        return [
            'company_id' => Company::factory(),
            'tenant_id' => fn (array $attributes) => Company::query()->whereKey($attributes['company_id'])->value('tenant_id'),
            'azure_oid' => (string) Str::uuid(),
            'display_name' => $name,
            'job_title' => fake()->jobTitle(),
            'department' => fake()->randomElement(['Finanzas', 'Operaciones', 'Comercial', 'Talento humano']),
            'office_location' => 'Bogotá',
            'business_phone' => '+57 601 555 '.fake()->numerify('####'),
            'email' => Str::slug($name, '.').'.'.fake()->unique()->numerify('####').'@example.com',
            'hire_date' => null,
            'synced_at' => now(),
        ];
    }

    /**
     * An entry of the given company, in its tenant.
     */
    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->id, 'tenant_id' => $company->tenant_id]);
    }
}
