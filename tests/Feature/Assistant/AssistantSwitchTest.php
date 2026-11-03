<?php

namespace Tests\Feature\Assistant;

use App\Assistant\AssistantSwitch;
use App\Audit\AuditPresenter;
use App\Audit\SecurityLog;
use App\Livewire\Admin\AssistantToggle;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The assistant is on only while the deploy flag (PORTAL_ASSISTANT_ENABLED)
 * and the Admin toggle «Asistente» are both on. Unlike the Claude
 * connector, the toggle starts off: someone has to turn it on.
 */
class AssistantSwitchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $company = Company::factory()->create(['name' => 'Segurex Latam S.A.S.', 'short_name' => 'Segurex']);
        $this->admin = User::factory()->microsoft($company)->create(['name' => 'Ana Admin', 'email' => 'ana.admin@segurex.com']);
        $this->admin->assignRole('super_admin');
    }

    private function switch(): AssistantSwitch
    {
        return app(AssistantSwitch::class);
    }

    public function test_the_admin_toggle_starts_off_and_the_assistant_with_it(): void
    {
        config(['assistant.enabled' => true]);

        $this->assertFalse($this->switch()->turnedOn());
        $this->assertFalse($this->switch()->enabled());
        $this->assertSame(0, DB::table('portal_settings')->count());
    }

    public function test_it_is_on_only_with_the_deploy_flag_and_the_toggle(): void
    {
        $this->switch()->turn(true, $this->admin);
        $this->assertFalse(app(AssistantSwitch::class)->enabled(), 'The deploy flag is off by default.');

        config(['assistant.enabled' => true]);
        $this->assertTrue(app(AssistantSwitch::class)->enabled());

        app(AssistantSwitch::class)->turn(false, $this->admin);
        $this->assertFalse(app(AssistantSwitch::class)->enabled());
    }

    public function test_only_a_saved_json_true_turns_it_on(): void
    {
        config(['assistant.enabled' => true]);

        foreach (['false', '1', '"on"', 'garbage'] as $value) {
            DB::table('portal_settings')->updateOrInsert(['key' => AssistantSwitch::SETTING], ['value' => $value]);
            $this->assertFalse(app(AssistantSwitch::class)->enabled(), $value);
        }

        DB::table('portal_settings')->updateOrInsert(['key' => AssistantSwitch::SETTING], ['value' => 'true']);
        $this->assertTrue(app(AssistantSwitch::class)->enabled());
    }

    public function test_configured_means_the_api_key_is_set(): void
    {
        config(['services.anthropic.key' => null]);
        $this->assertFalse($this->switch()->configured());

        config(['services.anthropic.key' => '   ']);
        $this->assertFalse($this->switch()->configured());

        config(['services.anthropic.key' => 'sk-ant-test']);
        $this->assertTrue($this->switch()->configured());
    }

    public function test_turning_it_is_audited_without_the_key_and_is_a_no_op_when_unchanged(): void
    {
        config(['services.anthropic.key' => 'sk-ant-secret-value']);

        $this->assertTrue($this->switch()->turn(true, $this->admin));
        $this->assertFalse(app(AssistantSwitch::class)->turn(true, $this->admin));

        $entry = Activity::query()->where('event', AssistantSwitch::SWITCHED)->sole();
        $this->assertSame(SecurityLog::LOG, $entry->log_name);
        $this->assertTrue($this->admin->is($entry->causer));
        $this->assertSame(['enabled' => true, 'deployed' => false, 'configured' => true], $entry->properties->all());
        $this->assertStringNotContainsString('sk-ant', json_encode($entry->properties));

        $this->assertSame(
            'Encendió el asistente · PORTAL_ASSISTANT_ENABLED está apagado en el servidor',
            app(AuditPresenter::class)->activity($entry)->what,
        );
    }

    public function test_the_audit_says_when_the_key_is_missing(): void
    {
        config(['assistant.enabled' => true, 'services.anthropic.key' => null]);

        $this->switch()->turn(true, $this->admin);

        $entry = Activity::query()->where('event', AssistantSwitch::SWITCHED)->sole();
        $this->assertSame(
            'Encendió el asistente · falta ANTHROPIC_API_KEY en el servidor',
            app(AuditPresenter::class)->activity($entry)->what,
        );
    }

    public function test_super_admins_see_the_toggle_in_configuracion(): void
    {
        $this->actingAs($this->admin)->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSeeText('Asistente del portal');
    }

    public function test_the_toggle_turns_it_on_and_off(): void
    {
        config(['assistant.enabled' => true, 'services.anthropic.key' => 'sk-ant-test']);

        Livewire::actingAs($this->admin)->test(AssistantToggle::class)
            ->assertSet('enabled', false)
            ->call('toggle')
            ->assertSet('enabled', true)
            ->assertSet('notice', 'Asistente encendido.')
            ->call('toggle')
            ->assertSet('enabled', false)
            ->assertSet('notice', 'Asistente apagado.');

        $this->assertSame(2, Activity::query()->where('event', AssistantSwitch::SWITCHED)->count());
    }

    public function test_a_stale_page_never_flips_it_back(): void
    {
        $page = Livewire::actingAs($this->admin)->test(AssistantToggle::class);

        // Someone else turns it on meanwhile; this page still shows «off».
        app(AssistantSwitch::class)->turn(true, $this->admin);

        $page->call('toggle')
            ->assertSet('enabled', true)
            ->assertSet('notice', 'Otra persona ya lo había encendido. El asistente sigue encendido.');

        $this->assertTrue(app(AssistantSwitch::class)->turnedOn());
    }

    public function test_the_toggle_warns_about_the_deploy_flag_and_the_key(): void
    {
        config(['assistant.enabled' => false, 'services.anthropic.key' => null]);

        Livewire::actingAs($this->admin)->test(AssistantToggle::class)
            ->assertSeeText('PORTAL_ASSISTANT_ENABLED está apagado en el servidor')
            ->assertSeeText('Falta ANTHROPIC_API_KEY en el servidor');
    }

    public function test_other_roles_are_forbidden(): void
    {
        foreach (['portal_admin', 'announcements_editor'] as $role) {
            $user = User::factory()->microsoft($this->admin->company)->create();
            $user->assignRole($role);

            Livewire::actingAs($user)->test(AssistantToggle::class)->assertForbidden();
        }

        $this->assertFalse(app(AssistantSwitch::class)->turnedOn());
    }
}
