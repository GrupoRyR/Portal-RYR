<?php

namespace Tests\Feature\Admin;

use App\Api\StatusReporter;
use App\Auth\RecentMicrosoftLogin;
use App\Livewire\Admin\IntegrationManager;
use App\Models\App;
use App\Models\Company;
use App\Models\Integration;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class IntegrationsPageTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        config(['portal.allowed_app_hosts' => ['segurex.com.co']]);
        $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00', 'America/Bogota'));

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'slug' => 'segurex']);
        $this->admin = $this->userWith('super_admin', ['name' => 'Jhon Castro']);
    }

    private function userWith(string $role, array $attributes = []): User
    {
        $user = User::factory()->microsoft($this->segurex)->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    private function integration(array $attributes = []): Integration
    {
        $integration = Integration::factory()->create([
            'name' => 'Monitor de disponibilidad',
            'abilities' => [Integration::CATALOG_READ, Integration::STATUS_WRITE],
            'created_by' => $this->admin->id,
            ...$attributes,
        ]);
        $integration->issueToken();

        return $integration->fresh();
    }

    private function manager(bool $fresh = true): Testable
    {
        if ($fresh) {
            session()->put(RecentMicrosoftLogin::SESSION_KEY, ['user_id' => $this->admin->id, 'at' => now()->getTimestamp()]);
        } else {
            session()->forget(RecentMicrosoftLogin::SESSION_KEY);
        }

        return Livewire::actingAs($this->admin)->test(IntegrationManager::class);
    }

    private function emergencyManager(): Testable
    {
        $emergency = User::factory()->emergency()->create();
        $emergency->assignRole('super_admin');

        return Livewire::actingAs($emergency)->test(IntegrationManager::class);
    }

    private function apiEntries(string $event)
    {
        return Activity::query()->where('log_name', 'api')->where('event', $event)->get();
    }

    // --- Access -------------------------------------------------------------

    public function test_super_admins_open_the_page_from_the_sidebar(): void
    {
        $this->get(route('admin.integrations.index'))->assertRedirect(route('login'));

        $this->actingAs($this->admin)->get(route('admin.integrations.index'))
            ->assertOk()
            ->assertSee('href="'.route('admin.integrations.index').'" aria-current="page"', false)
            ->assertSeeText('Tokens para que otros sistemas lean el catálogo o reporten el estado de las apps. Si nadie los usa, el portal funciona igual.')
            ->assertSeeText('Nuevo token')
            ->assertDontSeeText('Apps (validar acceso)')
            ->assertDontSeeText('Próximamente');
    }

    public function test_everyone_else_is_forbidden_and_sees_no_link(): void
    {
        $link = 'href="'.route('admin.integrations.index').'"';

        foreach (['portal_admin', 'announcements_editor'] as $role) {
            $user = $this->userWith($role);
            $this->actingAs($user)->get(route('admin.integrations.index'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.announcements.index'))->assertOk()->assertDontSee($link, false);
            Livewire::actingAs($user)->test(IntegrationManager::class)->assertForbidden();
        }

        $this->actingAs(User::factory()->create())->get(route('admin.integrations.index'))->assertForbidden();
    }

    // --- List and detail ----------------------------------------------------

    public function test_the_list_shows_each_system_with_its_masked_token(): void
    {
        $monitor = $this->integration();
        $monitor->forceFill(['last_used_at' => now()->subMinutes(2)])->save();
        $tool = $this->integration(['name' => 'Herramienta de gestión de TI', 'abilities' => [Integration::CATALOG_READ]]);
        $old = $this->integration(['name' => 'Token de pruebas', 'abilities' => [Integration::STATUS_WRITE]]);
        $old->revoke();

        $this->actingAs($this->admin)->get(route('admin.integrations.index'))
            ->assertSeeText('Sistemas externos · 3')
            ->assertSeeInOrder(['Sistema', 'Permisos', 'Último uso', 'Estado'])
            ->assertSeeInOrder(['Herramienta de gestión de TI', $tool->maskedToken(), 'catalog:read', 'Nunca', 'Activo'])
            ->assertSeeInOrder(['Monitor de disponibilidad', $monitor->maskedToken(), 'catalog:read', 'status:write', 'hace 2 minutos', 'Activo'])
            ->assertSeeInOrder(['Token de pruebas', 'status:write', 'Revocado']);
    }

    public function test_an_empty_list_invites_to_create_a_token(): void
    {
        $this->actingAs($this->admin)->get(route('admin.integrations.index'))
            ->assertSeeText('Aún no hay sistemas externos.');
    }

    public function test_the_detail_shows_abilities_ips_and_latest_changes(): void
    {
        $monitor = $this->integration(['allowed_ips' => ['10.0.0.5']]);
        $cartera = App::factory()->create(['slug' => 'cartera', 'url' => 'https://cartera.segurex.com.co']);
        $this->travelTo(CarbonImmutable::parse('2026-10-06 09:20', 'America/Bogota'));
        app(StatusReporter::class)->report($cartera, $monitor, ['status' => 'degraded']);
        $this->travelTo(CarbonImmutable::parse('2026-10-06 09:35', 'America/Bogota'));
        app(StatusReporter::class)->report($cartera, $monitor, ['status' => 'down']);
        $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00', 'America/Bogota'));

        $this->manager()->call('select', $monitor->id)->assertSet('selectedId', $monitor->id);

        $this->actingAs($this->admin)->get(route('admin.integrations.index', ['sistema' => $monitor->id]))
            ->assertSeeText('Creado 6 oct. 2026 por Jhon Castro · el token completo solo se mostró al crearlo')
            ->assertSeeTextInOrder(['Permisos', 'catalog:read', 'Leer el catálogo de apps, sin usuarios ni reglas', 'status:write', 'Reportar si una app funciona, falla o está caída'])
            ->assertSeeTextInOrder(['IP permitidas', '10.0.0.5'])
            ->assertSeeTextInOrder(['Últimos cambios que hizo', 'cartera', 'down', 'hace 25 minutos', 'cartera', 'degraded', 'hace 40 minutos'])
            ->assertSeeText('Rotar')
            ->assertSeeText('Revocar');
    }

    public function test_the_detail_is_reachable_by_its_address(): void
    {
        $monitor = $this->integration();

        $this->actingAs($this->admin)->get(route('admin.integrations.index', ['sistema' => $monitor->id]))
            ->assertSeeText('el token completo solo se mostró al crearlo');
    }

    // --- Creating -----------------------------------------------------------

    public function test_creating_a_token_shows_it_once_and_stores_only_its_hash(): void
    {
        $component = $this->manager()
            ->call('startCreating')
            ->set('name', 'Monitor de disponibilidad')
            ->set('abilities', [Integration::CATALOG_READ, Integration::STATUS_WRITE])
            ->set('ips', '10.0.0.5, 192.168.1.0/24')
            ->call('create')
            ->assertHasNoErrors()
            ->assertSee('Token creado')
            ->assertSee('Monitor de disponibilidad · catalog:read, status:write')
            ->assertSee('Cópialo ahora.')
            ->assertSee('PUT /api/v1/apps/cartera/status')
            ->assertSee('Ya lo guardé en un lugar seguro');

        $plain = $component->get('plainToken');
        $this->assertMatchesRegularExpression('/^ryr_[A-Za-z0-9]{48}$/', $plain);
        $component->assertSee($plain);

        $integration = Integration::query()->sole();
        $this->assertSame('Monitor de disponibilidad', $integration->name);
        $this->assertSame([Integration::CATALOG_READ, Integration::STATUS_WRITE], $integration->abilities);
        $this->assertSame(['10.0.0.5', '192.168.1.0/24'], $integration->allowed_ips);
        $this->assertTrue($integration->creator->is($this->admin));
        $this->assertSame(hash('sha256', $plain), PersonalAccessToken::query()->sole()->token);

        $entry = $this->apiEntries('integration.created')->sole();
        $this->assertTrue($entry->causer->is($this->admin));
        $this->assertTrue($entry->subject->is($integration));
        $this->assertStringNotContainsString($plain, json_encode($entry->properties));
        $this->assertStringNotContainsString(substr($plain, 4, 20), (string) DB::table('activity_log')->pluck('properties')->implode(' '));

        $component->call('dismissToken')
            ->assertSet('plainToken', null)
            ->assertDontSee($plain)
            ->assertSet('selectedId', $integration->id);
    }

    public function test_the_create_form_is_validated(): void
    {
        $this->manager()
            ->call('startCreating')
            ->set('name', '  ')
            ->set('abilities', [])
            ->set('ips', '10.0.0.5, servidor.local')
            ->call('create')
            ->assertHasErrors(['name', 'abilities', 'ips'])
            ->assertSee('Escribe el nombre del sistema.')
            ->assertSee('Elige al menos un permiso.')
            ->assertSee('«servidor.local» no es una IP ni un rango válido.');

        $this->manager()
            ->call('startCreating')
            ->set('name', 'Monitor')
            ->set('abilities', ['*'])
            ->call('create')
            ->assertHasErrors(['abilities.0']);

        $this->assertSame(0, Integration::query()->count());
    }

    // --- Recent Microsoft sign-in ------------------------------------------

    public function test_creating_needs_a_recent_microsoft_login(): void
    {
        $confirm = route('auth.microsoft.confirm', ['return' => '/admin/integraciones']);

        // The form only opens after the confirmation, so nothing typed is lost.
        $this->manager(fresh: false)
            ->call('startCreating')
            ->assertRedirect($confirm)
            ->assertSet('creating', false);

        // A crafted call straight to create() is refused as well.
        $this->manager(fresh: false)
            ->set('name', 'Monitor de disponibilidad')
            ->set('abilities', [Integration::CATALOG_READ])
            ->call('create')
            ->assertRedirect($confirm)
            ->assertSet('plainToken', null);

        $this->assertSame(0, Integration::query()->count());
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_an_expired_confirmation_is_asked_again_on_create(): void
    {
        $component = $this->manager()
            ->call('startCreating')
            ->assertSet('creating', true)
            ->set('name', 'Monitor de disponibilidad')
            ->set('abilities', [Integration::CATALOG_READ]);

        $this->travel(RecentMicrosoftLogin::TTL_SECONDS + 1)->seconds();

        $component->call('create')
            ->assertRedirect(route('auth.microsoft.confirm', ['return' => '/admin/integraciones']));

        $this->assertSame(0, Integration::query()->count());
    }

    public function test_rotating_needs_a_recent_microsoft_login(): void
    {
        $monitor = $this->integration();
        $oldHash = PersonalAccessToken::query()->sole()->token;

        $this->manager(fresh: false)
            ->call('select', $monitor->id)
            ->call('rotate')
            ->assertRedirect(route('auth.microsoft.confirm', ['return' => '/admin/integraciones?sistema='.$monitor->id]))
            ->assertSet('plainToken', null);

        $this->assertSame($oldHash, PersonalAccessToken::query()->sole()->token);
        $this->assertCount(0, $this->apiEntries('integration.rotated'));
    }

    public function test_emergency_accounts_cannot_create_or_rotate_tokens(): void
    {
        $monitor = $this->integration();
        $oldHash = PersonalAccessToken::query()->sole()->token;
        session()->put(RecentMicrosoftLogin::SESSION_KEY, ['user_id' => $this->admin->id, 'at' => now()->getTimestamp()]);

        $this->emergencyManager()->call('startCreating')->assertForbidden()->assertSet('creating', false);

        $this->emergencyManager()
            ->set('name', 'Monitor 2')
            ->set('abilities', [Integration::CATALOG_READ])
            ->call('create')
            ->assertForbidden();

        $this->emergencyManager()->call('select', $monitor->id)->call('rotate')->assertForbidden();

        $this->assertSame(1, Integration::query()->count());
        $this->assertSame($oldHash, PersonalAccessToken::query()->sole()->token);
    }

    public function test_emergency_accounts_are_told_why(): void
    {
        $this->withoutExceptionHandling();
        $this->expectExceptionMessage(IntegrationManager::EMERGENCY_MESSAGE);

        $this->emergencyManager()->call('startCreating');
    }

    // --- Rotating and revoking ---------------------------------------------

    public function test_rotating_shows_a_new_token_and_the_old_one_stops_working(): void
    {
        $monitor = $this->integration();
        $oldHash = PersonalAccessToken::query()->sole()->token;

        $component = $this->manager()
            ->call('select', $monitor->id)
            ->call('rotate')
            ->assertSee('Token nuevo')
            ->assertSee('El anterior ya no funciona.');

        $plain = $component->get('plainToken');
        $this->assertSame(hash('sha256', $plain), PersonalAccessToken::query()->sole()->token);
        $this->assertNotSame($oldHash, hash('sha256', $plain));
        $this->assertCount(1, $this->apiEntries('integration.rotated'));
    }

    public function test_revoking_deletes_the_token_for_good(): void
    {
        $monitor = $this->integration();

        $this->manager()
            ->call('select', $monitor->id)
            ->call('revoke')
            ->assertSee('Revocado')
            ->assertDontSee('wire:click="rotate"', false);

        $this->assertTrue($monitor->fresh()->isRevoked());
        $this->assertSame(0, PersonalAccessToken::query()->count());
        $this->assertCount(1, $this->apiEntries('integration.revoked'));

        // A revoked system cannot be rotated or changed any more.
        $this->manager()
            ->call('select', $monitor->id)
            ->call('rotate')
            ->call('toggleAbility', Integration::CATALOG_READ)
            ->set('newIp', '10.0.0.9')
            ->call('addIp')
            ->assertSet('plainToken', null);

        $this->assertSame(0, PersonalAccessToken::query()->count());
        $this->assertSame([Integration::CATALOG_READ, Integration::STATUS_WRITE], $monitor->fresh()->abilities);
        $this->assertSame([], $monitor->fresh()->allowed_ips ?? []);
    }

    // --- Abilities and IPs --------------------------------------------------

    public function test_abilities_can_be_changed_on_the_live_token(): void
    {
        $monitor = $this->integration();

        $this->manager()
            ->call('select', $monitor->id)
            ->call('toggleAbility', Integration::STATUS_WRITE)
            ->assertHasNoErrors();

        $this->assertSame([Integration::CATALOG_READ], $monitor->fresh()->abilities);
        $this->assertSame([Integration::CATALOG_READ], $monitor->fresh()->token()->first()->abilities);

        $entry = $this->apiEntries('integration.abilities_changed')->sole();
        $this->assertSame(['abilities' => [Integration::CATALOG_READ, Integration::STATUS_WRITE]], $entry->properties['old']);
        $this->assertSame(['abilities' => [Integration::CATALOG_READ]], $entry->properties['attributes']);

        $this->manager()
            ->call('select', $monitor->id)
            ->call('toggleAbility', Integration::CATALOG_READ)
            ->assertHasErrors('abilities')
            ->assertSee('Debe quedar al menos un permiso.');

        $this->assertSame([Integration::CATALOG_READ], $monitor->fresh()->abilities);
    }

    public function test_allowed_ips_are_added_and_removed(): void
    {
        $monitor = $this->integration();

        $this->manager()
            ->call('select', $monitor->id)
            ->set('newIp', 'no-es-ip')
            ->call('addIp')
            ->assertHasErrors('newIp')
            ->set('newIp', ' 10.0.0.5 ')
            ->call('addIp')
            ->assertHasNoErrors()
            ->set('newIp', '10.0.0.5')
            ->call('addIp')
            ->assertHasErrors('newIp')
            ->assertSee('Esa IP ya está en la lista.');

        $this->assertSame(['10.0.0.5'], $monitor->fresh()->allowed_ips);

        $this->manager()->call('select', $monitor->id)->call('removeIp', '10.0.0.5');
        $this->assertSame([], $monitor->fresh()->allowed_ips);

        $this->assertCount(2, $this->apiEntries('integration.ips_changed'));
    }

    // --- Auditoría ----------------------------------------------------------

    public function test_token_management_appears_in_auditoria_under_api(): void
    {
        $this->manager()
            ->call('startCreating')
            ->set('name', 'Monitor de disponibilidad')
            ->set('abilities', [Integration::CATALOG_READ])
            ->call('create');

        $this->actingAs($this->admin)->get(route('admin.audit.index', ['tipo' => 'api']))
            ->assertSeeTextInOrder(['Jhon Castro', 'API', 'Creó el token de Monitor de disponibilidad'])
            ->assertSeeTextInOrder(['Permisos', '—', 'catalog:read']);
    }
}
