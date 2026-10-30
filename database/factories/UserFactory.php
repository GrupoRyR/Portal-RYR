<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * A local emergency account with password and confirmed TOTP 2FA, and
     * no tenant, object ID or company.
     */
    public function emergency(?string $secret = null): static
    {
        return $this->state(fn () => [
            'name' => 'Cuenta de emergencia',
            'is_emergency' => true,
            'azure_oid' => null,
            'tenant_id' => null,
            'company_id' => null,
            'two_factor_secret' => $secret ?? (new Google2FA)->generateSecretKey(32),
            'two_factor_confirmed_at' => now(),
            'two_factor_last_timestep' => null,
        ]);
    }

    /**
     * A user signed in with Microsoft: an object ID in the tenant of the
     * given (or a new) company, as a home account (not a B2B guest).
     */
    public function microsoft(?Company $company = null): static
    {
        return $this->state(function () use ($company) {
            $company ??= Company::factory()->create();

            return [
                'azure_oid' => (string) Str::uuid(),
                'tenant_id' => $company->tenant_id,
                'company_id' => $company->id,
                'is_guest' => false,
            ];
        });
    }

    /**
     * A birthday («Mi cumpleaños» on /cuenta), shown to the company unless
     * $shown is false.
     */
    public function birthday(int $month, int $day, bool $shown = true): static
    {
        return $this->state(fn () => [
            'birthday_month' => $month,
            'birthday_day' => $day,
            'show_birthday' => $shown,
        ]);
    }
}
