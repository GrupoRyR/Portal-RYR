<?php

namespace Tests\Feature\Catalog;

use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\AppLaunch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AppLaunchTest extends TestCase
{
    use RefreshDatabase;

    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1';

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->user = User::factory()->microsoft($this->company)->create();
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $app = $this->openableApp();

        $this->get("/apps/{$app->slug}")->assertRedirect(route('login'));
    }

    public function test_an_app_the_user_cannot_open_is_not_found(): void
    {
        $app = App::factory()->forCompanies($this->company)->create();

        $this->actingAs($this->user)->get("/apps/{$app->slug}")->assertNotFound();
        $this->assertSame(0, AppLaunch::count());
    }

    public function test_an_unknown_slug_is_not_found(): void
    {
        $this->actingAs($this->user)->get('/apps/no-existe')->assertNotFound();
    }

    public function test_a_hidden_app_is_not_found_even_with_access(): void
    {
        $app = $this->openableApp(['status' => App::STATUS_HIDDEN]);

        $this->actingAs($this->user)->get("/apps/{$app->slug}")->assertNotFound();
        $this->assertSame(0, AppLaunch::count());
    }

    public function test_a_web_app_records_the_launch_and_redirects_to_its_url(): void
    {
        $this->travelTo(now()->startOfMinute());
        $app = $this->openableApp(['url' => 'https://pedidos.segurex.com.co']);

        $this->actingAs($this->user)
            ->withHeader('User-Agent', self::IPHONE)
            ->get("/apps/{$app->slug}")
            ->assertStatus(302)
            ->assertRedirect('https://pedidos.segurex.com.co');

        $launch = AppLaunch::sole();
        $this->assertSame($this->user->id, $launch->user_id);
        $this->assertSame($app->id, $launch->app_id);
        $this->assertSame($this->company->id, $launch->company_id);
        $this->assertSame('phone', $launch->device);
        $this->assertTrue($launch->launched_at->equalTo(now()));
    }

    public function test_launches_store_no_ip_address_or_user_agent(): void
    {
        $this->assertEqualsCanonicalizing(
            ['id', 'user_id', 'app_id', 'company_id', 'device', 'launched_at'],
            Schema::getColumnListing('app_launches'),
        );
    }

    public function test_a_failing_app_still_opens(): void
    {
        $app = $this->openableApp();
        $app->forceFill(['external_status_at' => now(), 'external_status' => 'down', 'external_status_message' => 'Estamos revisando'])->save();

        $this->actingAs($this->user)->get("/apps/{$app->slug}")->assertRedirect($app->url);
        $this->assertSame(1, AppLaunch::count());
    }

    public function test_an_app_in_maintenance_shows_the_notice_and_records_nothing(): void
    {
        $app = $this->openableApp([
            'name' => 'Toma Pedidos SAP',
            'status' => App::STATUS_MAINTENANCE,
            'maintenance_message' => 'Actualizamos la conexión con SAP.',
            'maintenance_until' => '2026-10-07 15:30:00',
        ]);

        $this->actingAs($this->user)->get("/apps/{$app->slug}")
            ->assertOk()
            ->assertSee('En mantenimiento')
            ->assertSee('Toma Pedidos SAP')
            ->assertSee('Actualizamos la conexión con SAP.')
            ->assertSee('7 de octubre')
            ->assertSee('Volver al inicio')
            ->assertSee(route('home'), false)
            ->assertDontSee($app->url);

        $this->assertSame(0, AppLaunch::count());
    }

    public function test_the_maintenance_notice_works_without_message_or_date(): void
    {
        $app = $this->openableApp(['status' => App::STATUS_MAINTENANCE, 'maintenance_message' => null, 'maintenance_until' => null]);

        $this->actingAs($this->user)->get("/apps/{$app->slug}")
            ->assertOk()
            ->assertSee('En mantenimiento')
            ->assertDontSee('Vuelve a estar disponible');
    }

    public function test_a_local_app_shows_its_info_page_and_records_the_launch(): void
    {
        $app = App::factory()->local()->forCompanies($this->company)->create([
            'name' => 'Cámaras',
            'local_instructions' => "Abre el menú Inicio de tu computador.\nEscribe «Cámaras» y ábrela.",
            'download_url' => 'https://descargas.segurex.com.co/camaras.msi',
        ]);
        AppAccessRule::forCompany($app, $this->company);

        $this->actingAs($this->user)->get("/apps/{$app->slug}")
            ->assertOk()
            ->assertSee('Cámaras')
            ->assertSee('Instalada en tu equipo')
            ->assertSeeInOrder(['Abre el menú Inicio de tu computador.', 'Escribe «Cámaras» y ábrela.'])
            ->assertSee('Descargar instalador')
            ->assertSee('https://descargas.segurex.com.co/camaras.msi', false)
            ->assertSee(route('home'), false);

        $this->assertSame('desktop', AppLaunch::sole()->device);
    }

    public function test_a_local_app_without_download_shows_no_download_link(): void
    {
        $app = App::factory()->local()->forCompanies($this->company)->create(['download_url' => null]);
        AppAccessRule::forCompany($app, $this->company);

        $this->actingAs($this->user)->get("/apps/{$app->slug}")
            ->assertOk()
            ->assertDontSee('Descargar instalador');
    }

    public function test_the_seeded_local_app_shows_its_icons_and_steps(): void
    {
        $this->seed();
        $segurex = Company::where('slug', 'segurex')->firstOrFail();
        $user = User::factory()->microsoft($segurex)->create();
        $user->syncGroups([['id' => 'aaaaaaaa-0000-0000-0000-000000000001', 'display_name' => 'APP-Camaras']]);

        $this->actingAs($user)->get('/apps/camaras')
            ->assertOk()
            ->assertSee(asset('icons/apps/camaras.svg'), false)
            ->assertSee(asset('icons/apps/camaras-dark.svg'), false)
            ->assertSee('Si no aparece, pide ayuda a TI.')
            ->assertDontSee('Descargar instalador');
    }

    public function test_the_local_page_escapes_the_instructions(): void
    {
        $app = App::factory()->local()->forCompanies($this->company)->create([
            'local_instructions' => '<script>alert(1)</script>',
            'download_url' => 'javascript:alert(1)',
        ]);
        AppAccessRule::forCompany($app, $this->company);

        $this->actingAs($this->user)->get("/apps/{$app->slug}")
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('javascript:alert(1)', false);
    }

    public function test_the_route_is_throttled(): void
    {
        $route = app('router')->getRoutes()->getByName('apps.open');

        $this->assertNotNull($route);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertNotEmpty(array_filter($route->gatherMiddleware(), fn ($middleware) => str_starts_with($middleware, 'throttle:')));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function openableApp(array $attributes = []): App
    {
        $app = App::factory()->forCompanies($this->company)->create($attributes);
        AppAccessRule::forCompany($app, $this->company);

        return $app;
    }
}
