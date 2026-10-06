<?php

namespace Tests\Feature\Catalog;

use App\Catalog\AppCatalog;
use App\Models\AppAccessRule;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\AppAccessRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccessRuleSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_initial_group_rules_of_the_brief(): void
    {
        $this->seed();

        $rules = AppAccessRule::with(['app', 'company'])->get()
            ->map(fn (AppAccessRule $rule) => "{$rule->company->slug}:{$rule->type}:{$rule->value}->{$rule->app->slug}")
            ->all();

        $this->assertEqualsCanonicalizing([
            'segurex:group:APP-TomaPedidos->toma-pedidos',
            'segurex:group:APP-CuentasPorPagar->cuentas-por-pagar',
            'segurex:group:APP-Cartera->cartera',
            'segurex:group:APP-Camaras->camaras',
            'derivados:group:APP-Portafolio->portafolio',
        ], $rules);
    }

    public function test_it_is_idempotent(): void
    {
        $this->seed();
        $this->seed(AppAccessRuleSeeder::class);

        $this->assertSame(5, AppAccessRule::count());
    }

    public function test_seeded_rules_give_each_group_its_app(): void
    {
        $this->seed();
        $segurex = Company::where('slug', 'segurex')->firstOrFail();
        $derivados = Company::where('slug', 'derivados')->firstOrFail();

        $this->assertSame(['cartera'], $this->visibleSlugs($segurex, ['APP-Cartera']));
        $this->assertSame(['portafolio'], $this->visibleSlugs($derivados, ['APP-Portafolio']));
        $this->assertSame([], $this->visibleSlugs($derivados, ['APP-Cartera']));
        $this->assertSame([], $this->visibleSlugs($segurex, []));
    }

    /**
     * @param  list<string>  $groups
     * @return list<string>
     */
    private function visibleSlugs(Company $company, array $groups): array
    {
        $user = User::factory()->microsoft($company)->create();
        $user->syncGroups(array_map(fn (string $name) => ['id' => (string) Str::uuid(), 'display_name' => $name], $groups));

        return app(AppCatalog::class)->visibleApps($user->fresh())->pluck('slug')->all();
    }
}
