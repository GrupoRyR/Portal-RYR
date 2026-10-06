<?php

namespace Database\Factories;

use App\Models\App;
use App\Models\AppCategory;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<App>
 */
class AppFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);
        $slug = Str::slug($name);

        return [
            'slug' => $slug,
            'name' => Str::ucfirst($name),
            'description' => fake()->sentence(4),
            'icon_path' => null,
            'icon_dark_path' => null,
            'accent_color' => '#4338CA',
            'url' => "https://{$slug}.segurex.com.co",
            'type' => App::TYPE_WEB,
            'open_in' => App::OPEN_NEW,
            'hosting' => 'godaddy',
            'login_type' => 'own',
            'app_category_id' => AppCategory::factory(),
            'sort_order' => 0,
            'status' => App::STATUS_ACTIVE,
        ];
    }

    /**
     * An app installed on the computer: no URL, an info page instead.
     */
    public function local(): static
    {
        return $this->state(fn () => [
            'type' => App::TYPE_LOCAL,
            'url' => null,
            'hosting' => 'local',
            'login_type' => 'local',
            'local_instructions' => "Abre el menú Inicio de tu computador.\nBusca la aplicación y ábrela.",
        ]);
    }

    public function maintenance(?string $message = null): static
    {
        return $this->state(fn () => [
            'status' => App::STATUS_MAINTENANCE,
            'maintenance_message' => $message ?? 'Estamos actualizando la aplicación.',
            'maintenance_until' => now()->addHours(2),
        ]);
    }

    public function hidden(): static
    {
        return $this->state(fn () => ['status' => App::STATUS_HIDDEN]);
    }

    /**
     * Linked to the given companies in `app_companies`.
     */
    public function forCompanies(Company ...$companies): static
    {
        return $this->afterCreating(fn (App $app) => $app->companies()->attach(collect($companies)->pluck('id')));
    }
}
