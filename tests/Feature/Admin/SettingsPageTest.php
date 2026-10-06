<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\SettingsEditor;
use App\Models\App;
use App\Models\Company;
use App\Models\User;
use App\Settings\PortalSettings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        config([
            'session.lifetime' => 480,
            'portal.allowed_app_hosts' => ['segurex.com.co', 'derivadoscol.com.co', 'sgx-*.vercel.app'],
        ]);

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'slug' => 'segurex']);
        $this->admin = $this->userWith('super_admin', ['name' => 'Jhon Castro']);
    }

    private function userWith(string $role, array $attributes = []): User
    {
        $user = User::factory()->microsoft($this->segurex)->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    private function settings(): PortalSettings
    {
        return app(PortalSettings::class);
    }

    // --- Access -------------------------------------------------------------

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get(route('admin.settings.index'))->assertRedirect(route('login'));
    }

    public function test_super_admins_open_the_page_from_the_sidebar(): void
    {
        $this->actingAs($this->admin)->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('href="'.route('admin.settings.index').'" aria-current="page"', false)
            ->assertSeeText('Ajustes que aplican a todo el portal y a todas las empresas.')
            ->assertSeeInOrder(['Soporte TI', 'Sesión', 'Dominios permitidos para apps', 'Estados reportados por la API', 'Respaldo diario de la base de datos'])
            ->assertSeeText('Próximamente')
            ->assertDontSeeText('Respaldar ahora')
            ->assertDontSeeText('Último respaldo');
    }

    public function test_portal_admins_editors_and_plain_users_are_forbidden_and_see_no_link(): void
    {
        $link = 'href="'.route('admin.settings.index').'"';

        foreach (['portal_admin', 'announcements_editor'] as $role) {
            $user = $this->userWith($role);
            $this->actingAs($user)->get(route('admin.settings.index'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.announcements.index'))->assertOk()->assertDontSee($link, false);
        }

        $this->actingAs(User::factory()->create())->get(route('admin.settings.index'))->assertForbidden();
    }

    public function test_portal_admins_no_longer_see_configuracion_as_coming_soon(): void
    {
        $this->actingAs($this->userWith('portal_admin'))->get(route('admin.apps.index'))
            ->assertOk()
            ->assertDontSeeText('Configuración');
    }

    public function test_mounting_and_crafted_calls_are_reauthorized(): void
    {
        $portalAdmin = $this->userWith('portal_admin');

        Livewire::actingAs($portalAdmin)->test(SettingsEditor::class)->assertForbidden();

        foreach ([['save'], ['addHost'], ['removeHost', 'segurex.com.co']] as $call) {
            $component = Livewire::actingAs($this->admin)->test(SettingsEditor::class)
                ->set('supportUrl', 'https://evil.example.com')
                ->set('newHost', 'evil.example.com');
            $this->actingAs($portalAdmin);
            $component->call(...$call)->assertForbidden();
        }

        $this->assertNull($this->settings()->supportUrl());
        $this->assertNotContains('evil.example.com', $this->settings()->allowedAppHosts());
    }

    // --- Form ---------------------------------------------------------------

    public function test_the_form_starts_from_the_current_values(): void
    {
        Livewire::actingAs($this->admin)->test(SettingsEditor::class)
            ->assertSet('supportUrl', '')
            ->assertSet('idleTimeout', 480)
            ->assertSet('microsoftLogout', false)
            ->assertSet('hosts', ['segurex.com.co', 'derivadoscol.com.co', 'sgx-*.vercel.app'])
            ->assertSet('apiStatusTtl', 2)
            ->assertSeeHtml('value="480" selected');
    }

    public function test_saving_stores_every_setting_and_audits_it(): void
    {
        Livewire::actingAs($this->admin)->test(SettingsEditor::class)
            ->set('supportUrl', 'https://soporte.segurex.com.co/tickets/nuevo')
            ->set('idleTimeout', 1440)
            ->set('microsoftLogout', true)
            ->set('newHost', 'Toma.Pedidos.co ')
            ->call('addHost')
            ->assertSet('newHost', '')
            ->call('removeHost', 'derivadoscol.com.co')
            ->set('apiStatusTtl', 4)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Cambios guardados.');

        $this->assertSame('https://soporte.segurex.com.co/tickets/nuevo', $this->settings()->supportUrl());
        $this->assertSame(1440, $this->settings()->idleTimeoutMinutes());
        $this->assertTrue($this->settings()->signsOutOfMicrosoft());
        $this->assertSame(['segurex.com.co', 'sgx-*.vercel.app', 'toma.pedidos.co'], $this->settings()->allowedAppHosts());
        $this->assertSame(4, $this->settings()->apiStatusTtlHours());

        $activity = Activity::query()->where('log_name', PortalSettings::AUDIT_LOG)->sole();
        $this->assertTrue($activity->causer->is($this->admin));

        $this->get(route('admin.audit.index'))->assertSeeText('Cambió la configuración del portal');
    }

    public function test_removing_a_domain_is_only_applied_on_save(): void
    {
        Livewire::actingAs($this->admin)->test(SettingsEditor::class)
            ->call('removeHost', 'derivadoscol.com.co')
            ->assertSet('hosts', ['segurex.com.co', 'sgx-*.vercel.app']);

        $this->assertContains('derivadoscol.com.co', $this->settings()->allowedAppHosts());
    }

    public function test_the_support_url_must_be_https_and_reasonable(): void
    {
        foreach (['http://soporte.segurex.com.co', 'javascript:alert(1)', 'soporte', 'https://user:pw@soporte.segurex.com.co', 'https://'.str_repeat('a', 500).'.co'] as $url) {
            Livewire::actingAs($this->admin)->test(SettingsEditor::class)
                ->set('supportUrl', $url)
                ->call('save')
                ->assertHasErrors('supportUrl');
        }

        $this->assertNull($this->settings()->supportUrl());
    }

    public function test_the_choices_are_limited(): void
    {
        Livewire::actingAs($this->admin)->test(SettingsEditor::class)
            ->set('idleTimeout', 5)
            ->set('apiStatusTtl', 3)
            ->call('save')
            ->assertHasErrors(['idleTimeout', 'apiStatusTtl']);
    }

    public function test_the_server_lifetime_is_offered_when_it_is_not_a_standard_choice(): void
    {
        config(['session.lifetime' => 120]);

        Livewire::actingAs($this->admin)->test(SettingsEditor::class)
            ->assertSet('idleTimeout', 120)
            ->assertSeeHtml('value="120" selected')
            ->assertSee('2 horas (valor actual del servidor)')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(0, Activity::query()->where('log_name', PortalSettings::AUDIT_LOG)->count());
    }

    public function test_the_ticket_example_shows_the_final_url(): void
    {
        Livewire::actingAs($this->admin)->test(SettingsEditor::class)
            ->assertSee('[URL de tickets]?origen=portal&amp;empresa=segurex&amp;app=', false)
            ->set('supportUrl', 'https://soporte.segurex.com.co/nuevo?form=ti')
            ->assertSee('https://soporte.segurex.com.co/nuevo?form=ti&amp;origen=portal&amp;empresa=segurex&amp;app=', false);
    }

    // --- Domains ------------------------------------------------------------

    public function test_invalid_or_duplicate_domains_are_refused(): void
    {
        foreach (['*.vercel.app', 'https://x.co', 'localhost', 'segurex.com.co'] as $host) {
            Livewire::actingAs($this->admin)->test(SettingsEditor::class)
                ->set('newHost', $host)
                ->call('addHost')
                ->assertHasErrors('newHost')
                ->assertCount('hosts', 3);
        }
    }

    public function test_a_domain_used_by_a_visible_app_cannot_be_removed(): void
    {
        App::factory()->create(['name' => 'Cartera', 'url' => 'https://cartera.derivadoscol.com.co']);
        App::factory()->maintenance()->create(['name' => 'Pedidos', 'url' => 'https://pedidos.derivadoscol.com.co']);
        App::factory()->hidden()->create(['name' => 'Vieja', 'url' => 'https://sgx-vieja.vercel.app']);

        Livewire::actingAs($this->admin)->test(SettingsEditor::class)
            ->call('removeHost', 'derivadoscol.com.co')
            ->assertHasErrors('hosts')
            ->assertSee('Cartera, Pedidos')
            ->assertSet('hosts', ['segurex.com.co', 'derivadoscol.com.co', 'sgx-*.vercel.app'])
            // A hidden app does not hold its domain.
            ->call('removeHost', 'sgx-*.vercel.app')
            ->assertHasNoErrors()
            ->assertSet('hosts', ['segurex.com.co', 'derivadoscol.com.co']);
    }

    public function test_saving_rechecks_the_domains_in_use(): void
    {
        $component = Livewire::actingAs($this->admin)->test(SettingsEditor::class)
            ->call('removeHost', 'derivadoscol.com.co');

        // An app on that domain published after the domain was removed in the form.
        App::factory()->create(['name' => 'Cartera', 'url' => 'https://cartera.derivadoscol.com.co']);

        $component->call('save')->assertHasErrors('hosts');
        $this->assertContains('derivadoscol.com.co', $this->settings()->allowedAppHosts());
    }

    public function test_the_last_domain_cannot_be_removed(): void
    {
        $this->settings()->update(['allowed_app_hosts' => ['segurex.com.co']], null);

        Livewire::actingAs($this->admin)->test(SettingsEditor::class)
            ->call('removeHost', 'segurex.com.co')
            ->assertHasErrors('hosts')
            ->assertSet('hosts', ['segurex.com.co']);
    }
}
