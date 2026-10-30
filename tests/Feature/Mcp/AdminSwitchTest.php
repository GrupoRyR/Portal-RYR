<?php

namespace Tests\Feature\Mcp;

use App\Audit\SecurityLog;
use App\Mcp\McpSwitch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The connector is on only while the deploy flag (PORTAL_MCP_ENABLED) and
 * the Admin toggle «Conector de Claude» are both on. The flag is the hard
 * kill switch; the toggle turns it off at runtime, at once.
 */
class AdminSwitchTest extends TestCase
{
    use InteractsWithMcp, RefreshDatabase;

    private function switch(): McpSwitch
    {
        return app(McpSwitch::class);
    }

    private function admin(): User
    {
        return User::factory()->microsoft()->create(['name' => 'Jhon Castro']);
    }

    private function assertConnectorRoutesAreNotFound(?string $token = null): void
    {
        $this->get('/.well-known/oauth-protected-resource/mcp/portal')->assertNotFound();
        $this->get('/.well-known/oauth-authorization-server')->assertNotFound();
        $this->registerClient()->assertNotFound();
        $this->get('/oauth/authorize?client_id=x')->assertNotFound();
        $this->post('/oauth/token', ['grant_type' => 'authorization_code'])->assertNotFound();
        $this->mcp($token, 'tools/list')->assertNotFound();
    }

    public function test_the_admin_toggle_is_on_until_someone_turns_it_off(): void
    {
        $this->assertTrue($this->switch()->turnedOn());
        $this->assertSame(0, DB::table('portal_settings')->count());
    }

    public function test_the_deploy_flag_alone_turns_the_connector_on_while_the_toggle_was_never_touched(): void
    {
        $this->enableMcp();

        $this->assertTrue($this->switch()->enabled());
        $this->get('/.well-known/oauth-protected-resource/mcp/portal')->assertOk();
    }

    public function test_turning_the_toggle_off_closes_every_connector_route_at_once(): void
    {
        $this->enableMcp();
        $tokens = $this->connect($this->eligibleUser());
        $this->mcp($tokens['access_token'], 'tools/list')->assertOk();

        $this->switch()->turn(false, $this->admin());

        $this->assertFalse($this->switch()->enabled());
        $this->assertConnectorRoutesAreNotFound($tokens['access_token']);
    }

    public function test_turning_it_on_again_works_with_the_same_tokens(): void
    {
        $this->enableMcp();
        $tokens = $this->connect($this->eligibleUser());
        $admin = $this->admin();

        $this->switch()->turn(false, $admin);
        $this->mcp($tokens['access_token'], 'tools/list')->assertNotFound();

        $this->switch()->turn(true, $admin);

        $this->assertTrue($this->switch()->enabled());
        $this->mcp($tokens['access_token'], 'tools/list')->assertOk();
        $this->registerClient()->assertCreated();
    }

    public function test_the_deploy_flag_off_overrides_the_toggle(): void
    {
        $this->switch()->turn(true, $this->admin());

        $this->assertTrue($this->switch()->turnedOn());
        $this->assertFalse($this->switch()->deployed());
        $this->assertFalse($this->switch()->enabled());
        $this->assertConnectorRoutesAreNotFound();
    }

    public function test_the_toggle_is_stored_in_the_portal_settings(): void
    {
        $this->switch()->turn(false, $this->admin());

        $this->assertSame('false', DB::table('portal_settings')->where('key', McpSwitch::SETTING)->value('value'));
        $this->assertFalse(app(McpSwitch::class)->turnedOn());
    }

    public function test_a_change_made_by_another_process_is_seen_on_the_next_request(): void
    {
        $this->enableMcp();
        $this->get('/.well-known/oauth-authorization-server')->assertOk();

        // Another PHP process (another worker) turns it off: nothing cached here.
        DB::table('portal_settings')->insert(['key' => McpSwitch::SETTING, 'value' => 'false', 'updated_at' => now()]);
        $this->get('/.well-known/oauth-authorization-server')->assertNotFound();

        DB::table('portal_settings')->where('key', McpSwitch::SETTING)->update(['value' => 'true']);
        $this->get('/.well-known/oauth-authorization-server')->assertOk();
    }

    public function test_switching_is_audited_with_who_and_the_new_state(): void
    {
        $admin = $this->admin();

        $this->assertTrue($this->switch()->turn(false, $admin));
        $this->assertTrue($this->switch()->turn(true, $admin));

        $entries = Activity::query()->where('event', McpSwitch::SWITCHED)->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertSame(SecurityLog::LOG, $entries[0]->log_name);
        $this->assertTrue($entries[0]->causer->is($admin));
        $this->assertFalse($entries[0]->properties['enabled']);
        $this->assertTrue($entries[1]->properties['enabled']);
        $this->assertFalse($entries[1]->properties['deployed']);
    }

    public function test_switching_to_the_current_state_changes_and_audits_nothing(): void
    {
        $this->assertFalse($this->switch()->turn(true, $this->admin()));

        $this->assertSame(0, DB::table('portal_settings')->count());
        $this->assertSame(0, Activity::query()->where('event', McpSwitch::SWITCHED)->count());
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function storedValues(): array
    {
        return [
            'JSON true' => ['true', true],
            'JSON false' => ['false', false],
            'zero' => ['0', false],
            'one' => ['1', false],
            'off' => ['off', false],
            'quoted false' => ['"false"', false],
            'quoted true' => ['"true"', false],
            'empty' => ['', false],
            'null' => ['null', false],
            'garbage' => ['{nope', false],
        ];
    }

    /**
     * Only a stored JSON `true` (or no row at all) is on: anything else,
     * corrupt or hand-edited, fails closed.
     */
    #[DataProvider('storedValues')]
    public function test_only_a_stored_json_true_reads_as_on(string $stored, bool $on): void
    {
        $this->enableMcp();
        DB::table('portal_settings')->insert(['key' => McpSwitch::SETTING, 'value' => $stored, 'updated_at' => now()]);

        $this->assertSame($on, app(McpSwitch::class)->turnedOn());
        $this->assertSame($on, app(McpSwitch::class)->enabled());
    }

    public function test_reading_the_switch_with_the_deploy_flag_off_runs_no_query(): void
    {
        DB::enableQueryLog();
        $this->switch()->enabled();

        $this->assertSame([], DB::getQueryLog());
    }
}
