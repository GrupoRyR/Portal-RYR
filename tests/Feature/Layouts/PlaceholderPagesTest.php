<?php

namespace Tests\Feature\Layouts;

use App\Branding\BrandPalette;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaceholderPagesTest extends TestCase
{
    use RefreshDatabase;

    private const EMERGENCY_PATH = '/acceso-emergencia';

    public function test_the_login_page_uses_the_neutral_layout(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('<html lang="es">', false)
            ->assertSee('<style>'.BrandPalette::neutral()->toCss().'</style>', false)
            ->assertSee('Todas tus aplicaciones en un solo lugar')
            ->assertSee('/build/assets/', false);
    }

    public function test_the_login_page_lists_only_active_companies(): void
    {
        Company::factory()->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);
        Company::factory()->create(['name' => 'Soluciones y Derivados', 'short_name' => 'Derivados']);
        Company::factory()->create(['name' => 'Empresa Cerrada', 'short_name' => 'Cerrada', 'is_active' => false]);

        $this->get('/login')
            ->assertOk()
            ->assertSee('Segurex')
            ->assertSee('Derivados')
            ->assertDontSee('Cerrada');
    }

    public function test_the_emergency_pages_use_the_layout_and_are_not_indexed(): void
    {
        $this->get(self::EMERGENCY_PATH)
            ->assertOk()
            ->assertSee('<html lang="es">', false)
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);

        // The code step needs a pending sign-in; its flow is covered in
        // EmergencyLoginTest, so the view is rendered directly here.
        $this->withViewErrors([])
            ->view('auth.emergency-code')
            ->assertSee('<html lang="es">', false)
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }

    public function test_the_home_page_uses_the_company_brand(): void
    {
        $company = Company::factory()->create([
            'name' => 'Segurex Latam',
            'color_primary' => '#FF671F',
            'color_accent' => '#666666',
            'color_dark' => '#8F360B',
        ]);
        $user = User::factory()->create(['name' => 'Ana Pérez', 'company_id' => $company->id]);

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('<html lang="es">', false)
            ->assertSee('<style>'.BrandPalette::forCompany($company)->toCss().'</style>', false)
            ->assertSee('Segurex Latam')
            ->assertSee('Hola, Ana Pérez');
    }

    public function test_the_home_page_links_to_administration_only_for_admins(): void
    {
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')->assertDontSee(route('admin.index'), false);

        $user->assignRole('portal_admin');

        $this->actingAs($user->fresh())->get('/')->assertSee('href="'.route('admin.index').'"', false);
    }
}
