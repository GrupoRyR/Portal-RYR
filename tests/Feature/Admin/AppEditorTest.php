<?php

namespace Tests\Feature\Admin;

use App\Api\StatusReporter;
use App\Livewire\Admin\AppEditor;
use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\AppCategory;
use App\Models\Company;
use App\Models\Integration;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AppEditorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $segurex;

    private Company $derivados;

    private AppCategory $ventas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->segurex = Company::factory()->create(['short_name' => 'Segurex', 'slug' => 'segurex']);
        $this->derivados = Company::factory()->create(['short_name' => 'Derivados', 'slug' => 'derivados']);
        $this->ventas = AppCategory::factory()->create(['name' => 'Ventas', 'slug' => 'ventas', 'sort_order' => 1]);

        $this->admin = User::factory()->create(['name' => 'Jhon Castro']);
        $this->admin->assignRole('portal_admin');
    }

    // --- Pages and access -------------------------------------------------

    public function test_the_policy_matrix_for_the_editor_pages(): void
    {
        $app = App::factory()->create(['slug' => 'cartera']);
        $super = User::factory()->create();
        $super->assignRole('super_admin');

        foreach ([$this->admin, $super] as $user) {
            $this->actingAs($user)->get(route('admin.apps.create'))->assertOk()->assertSee('Nueva aplicación');
            $this->actingAs($user)->get(route('admin.apps.edit', $app))->assertOk()->assertSee($app->name);
        }

        $this->actingAs(User::factory()->create())->get(route('admin.apps.create'))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('admin.apps.edit', $app))->assertForbidden();

        auth()->logout();
        $this->get(route('admin.apps.create'))->assertRedirect(route('login'));
        $this->get(route('admin.apps.edit', $app))->assertRedirect(route('login'));
    }

    public function test_the_component_refuses_users_without_an_admin_role(): void
    {
        Livewire::actingAs(User::factory()->create())->test(AppEditor::class)->assertForbidden();
    }

    public function test_the_list_links_to_the_editor_and_to_a_new_app(): void
    {
        $app = App::factory()->create(['slug' => 'cartera']);

        $this->actingAs($this->admin)->get(route('admin.apps.index'))
            ->assertSee('href="'.route('admin.apps.create').'"', false)
            ->assertSee('href="'.route('admin.apps.edit', $app).'"', false);
    }

    // --- Create -----------------------------------------------------------

    public function test_a_web_app_is_created(): void
    {
        $this->creator()
            ->set('name', 'Visualizador de cartera')
            ->set('slug', 'cartera')
            ->set('description', 'Cartera por cliente y asesor')
            ->set('companyIds', [$this->segurex->id])
            ->set('url', 'https://sgx-visualizador-cartera.vercel.app/login')
            ->set('openIn', 'new')
            ->set('hosting', 'vercel')
            ->set('loginType', 'own')
            ->set('accentColor', '#b45309')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.apps.edit', 'cartera'));

        $app = App::where('slug', 'cartera')->firstOrFail();

        $this->assertSame('web', $app->type);
        $this->assertSame('https://sgx-visualizador-cartera.vercel.app/login', $app->url);
        $this->assertSame('#B45309', $app->accent_color);
        $this->assertSame($this->ventas->id, $app->app_category_id);
        $this->assertSame([$this->segurex->id], $app->companies->pluck('id')->all());
        $this->assertFalse($app->available_to_all_companies);
        $this->assertSame(App::STATUS_ACTIVE, $app->status);
    }

    public function test_a_local_app_is_created_without_a_url(): void
    {
        $this->creator()
            ->set('name', 'Cámaras')
            ->set('slug', 'camaras')
            ->set('description', 'Ver las cámaras de la sede')
            ->set('type', 'local')
            ->set('localInstructions', "Abre el menú Inicio.\nBusca «Cámaras».")
            ->set('downloadUrl', 'https://segurex.com.co/descargas/camaras.exe')
            ->call('save')
            ->assertHasNoErrors();

        $app = App::where('slug', 'camaras')->firstOrFail();

        $this->assertSame('local', $app->type);
        $this->assertNull($app->url);
        $this->assertSame('local', $app->hosting);
        $this->assertSame('local', $app->login_type);
        $this->assertSame('https://segurex.com.co/descargas/camaras.exe', $app->download_url);
        $this->assertStringContainsString('Busca «Cámaras».', $app->local_instructions);
    }

    public function test_the_slug_is_suggested_from_the_name_until_edited(): void
    {
        $component = $this->creator()->set('name', 'Toma Pedidos SAP')->assertSet('slug', 'toma-pedidos-sap');

        $component->set('slug', 'toma-pedidos')->set('name', 'Toma Pedidos')->assertSet('slug', 'toma-pedidos');
    }

    public function test_the_slug_must_be_kebab_case_unique_and_not_reserved(): void
    {
        App::factory()->create(['slug' => 'cartera']);

        foreach (['Cartera Nueva', 'cartera_2', '-cartera', 'cartera-', 'cartera--2'] as $slug) {
            $this->validWebApp()->set('slug', $slug)->call('save')->assertHasErrors('slug');
        }

        $this->validWebApp()->set('slug', 'cartera')->call('save')
            ->assertHasErrors('slug')
            ->assertSee('Ya hay una aplicación con ese identificador.');
        $this->validWebApp()->set('slug', 'nueva')->call('save')->assertHasErrors('slug');
    }

    public function test_web_urls_must_be_https_on_an_allowed_host(): void
    {
        $this->validWebApp()->set('url', 'http://cartera.segurex.com.co')->call('save')->assertHasErrors('url');
        $this->validWebApp()->set('url', 'https://cartera.evil.example')->call('save')
            ->assertHasErrors('url')
            ->assertSee('La dirección debe empezar por https:// y pertenecer a un dominio permitido.');
        $this->validWebApp()->set('url', '')->call('save')->assertHasErrors('url');

        $this->assertSame(0, App::count());
    }

    public function test_local_apps_need_instructions_and_an_https_download(): void
    {
        $this->validWebApp()->set('type', 'local')->set('localInstructions', '')->call('save')->assertHasErrors('localInstructions');
        $this->validWebApp()->set('type', 'local')->set('localInstructions', 'Ábrela.')
            ->set('downloadUrl', 'http://segurex.com.co/app.exe')->call('save')->assertHasErrors('downloadUrl');
    }

    public function test_the_description_and_accent_color_are_checked(): void
    {
        $this->validWebApp()->set('description', str_repeat('a', 121))->call('save')->assertHasErrors('description');
        $this->validWebApp()->set('accentColor', 'naranja')->call('save')->assertHasErrors('accentColor');
    }

    public function test_maintenance_needs_a_message(): void
    {
        $this->validWebApp()->set('status', 'maintenance')->set('maintenanceMessage', '')->call('save')
            ->assertHasErrors('maintenanceMessage')
            ->assertSee('Escribe el mensaje que verán las personas.');
        $this->validWebApp()->set('status', 'maintenance')->set('maintenanceMessage', str_repeat('a', 201))->call('save')
            ->assertHasErrors('maintenanceMessage');

        $this->validWebApp()
            ->set('status', 'maintenance')
            ->set('maintenanceMessage', 'Actualizamos la versión.')
            ->set('maintenanceUntil', '2026-10-10T18:00')
            ->call('save')
            ->assertHasNoErrors();

        $app = App::firstOrFail();
        $this->assertSame('Actualizamos la versión.', $app->maintenance_message);
        $this->assertSame('2026-10-10 18:00', $app->maintenance_until->format('Y-m-d H:i'));
    }

    // --- Update -----------------------------------------------------------

    public function test_an_app_is_updated_and_its_slug_stays(): void
    {
        $app = App::factory()->forCompanies($this->segurex)->create(['slug' => 'cartera', 'name' => 'Cartera']);

        $this->editor($app)
            ->assertSee('Fijo. Lo usan la API y el botón de Soporte TI.')
            ->set('slug', 'otra')
            ->set('name', 'Visualizador de cartera')
            ->set('companyIds', [$this->derivados->id])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.apps.edit', 'cartera'));

        $app->refresh();
        $this->assertSame('cartera', $app->slug);
        $this->assertSame('Visualizador de cartera', $app->name);
        $this->assertSame([$this->derivados->id], $app->companies->pluck('id')->all());
    }

    public function test_leaving_maintenance_clears_its_message(): void
    {
        $app = App::factory()->maintenance('Volvemos pronto.')->create();

        $this->editor($app)->assertSet('maintenanceMessage', 'Volvemos pronto.')->set('status', 'active')->call('save')->assertHasNoErrors();

        $this->assertNull($app->fresh()->maintenance_message);
        $this->assertNull($app->fresh()->maintenance_until);
    }

    public function test_the_all_companies_flag_reaches_existing_and_new_companies(): void
    {
        $app = App::factory()->create(['slug' => 'intranet']);
        AppAccessRule::forCompany($app, $this->derivados);

        $this->editor($app)->set('allCompanies', true)->call('save')->assertHasNoErrors();

        $this->assertTrue($app->fresh()->available_to_all_companies);
        $this->actingAs(User::factory()->microsoft($this->derivados)->create())
            ->get(route('apps.open', $app))->assertRedirect($app->url);

        $newcomer = Company::factory()->create();
        AppAccessRule::forCompany($app, $newcomer);
        $this->actingAs(User::factory()->microsoft($newcomer)->create())
            ->get(route('apps.open', $app))->assertRedirect($app->url);
    }

    // --- Reported status ----------------------------------------------------

    public function test_the_reported_status_is_shown_and_can_be_cleared(): void
    {
        $app = App::factory()->create();
        $app->forceFill([
            'external_status' => 'down',
            'external_status_message' => 'Estamos revisando',
            'external_status_at' => now()->subMinutes(25),
        ])->save();

        $this->editor($app)
            ->assertSee('Estado reportado')
            ->assertSee('«Estamos revisando»')
            ->assertSee('hace 25 minutos')
            ->assertSee('El mantenimiento manda sobre lo que reporten otros sistemas.')
            ->call('clearExternalStatus')
            ->assertHasNoErrors()
            ->assertSee('Ningún sistema ha reportado fallas.');

        $app->refresh();
        $this->assertNull($app->external_status);
        $this->assertNull($app->external_status_message);
        $this->assertNull($app->external_status_at);

        $entry = Activity::where('event', 'app.external_status_cleared')->firstOrFail();
        $this->assertTrue($entry->causer->is($this->admin));
        $this->assertSame('down', $entry->properties['old']['external_status']);
    }

    public function test_an_expired_report_is_marked_and_the_recent_history_is_listed(): void
    {
        $app = App::factory()->create(['slug' => 'cartera']);
        $monitor = Integration::factory()->create(['name' => 'Monitor de disponibilidad', 'abilities' => [Integration::STATUS_WRITE]]);
        $this->travel(-3)->hours();
        app(StatusReporter::class)->report($app, $monitor, ['status' => 'degraded', 'message' => 'Lento']);
        $this->travelBack();
        $this->travel(-150)->minutes();
        app(StatusReporter::class)->report($app, $monitor, ['status' => 'down', 'message' => 'Caída']);
        $this->travelBack();

        $this->editor($app->fresh())
            ->assertSee('Ya no se muestra en el inicio: pasaron más de 2 horas sin reportes.')
            ->assertSeeInOrder(['Últimos reportes', 'Caída', 'Monitor de disponibilidad', 'Degradada', 'Monitor de disponibilidad']);
    }

    // --- Activity log -------------------------------------------------------

    public function test_creating_and_updating_are_logged_with_the_causer_and_changed_fields(): void
    {
        $this->validWebApp()->call('save')->assertHasNoErrors();
        $app = App::firstOrFail();

        $created = Activity::where('log_name', App::AUDIT_LOG)->where('event', 'created')->firstOrFail();
        $this->assertTrue($created->causer->is($this->admin));
        $this->assertTrue($created->subject->is($app));

        $this->editor($app)->set('name', 'Otro nombre')->call('save');

        $updated = Activity::where('log_name', App::AUDIT_LOG)->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertSame(['name' => 'Otro nombre'], $updated->properties['attributes']);
        $this->assertSame(['name' => 'Toma Pedidos SAP'], $updated->properties['old']);
    }

    public function test_status_and_company_changes_get_named_entries(): void
    {
        $app = App::factory()->forCompanies($this->segurex)->create();

        $this->editor($app)
            ->set('status', 'maintenance')
            ->set('maintenanceMessage', 'Actualizando.')
            ->set('companyIds', [$this->segurex->id, $this->derivados->id])
            ->call('save')
            ->assertHasNoErrors();

        $status = Activity::where('event', 'app.status_changed')->firstOrFail();
        $this->assertSame(['status' => 'active'], $status->properties['old']);
        $this->assertSame('maintenance', $status->properties['attributes']['status']);
        $this->assertTrue($status->causer->is($this->admin));

        $companies = Activity::where('event', 'app.companies_changed')->firstOrFail();
        $this->assertSame(['Segurex'], $companies->properties['old']['companies']);
        $this->assertSame(['Derivados', 'Segurex'], $companies->properties['attributes']['companies']);
    }

    private function creator(): Testable
    {
        return Livewire::actingAs($this->admin)->test(AppEditor::class);
    }

    private function editor(App $app): Testable
    {
        return Livewire::actingAs($this->admin)->test(AppEditor::class, ['app' => $app]);
    }

    private function validWebApp(): Testable
    {
        return $this->creator()
            ->set('name', 'Toma Pedidos SAP')
            ->set('slug', 'toma-pedidos')
            ->set('description', 'Pedidos de venta en SAP')
            ->set('companyIds', [$this->segurex->id])
            ->set('url', 'https://pedidos.segurex.com.co');
    }
}
