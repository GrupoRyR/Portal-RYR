<?php

namespace Tests\Feature\Api;

use App\Models\App;
use App\Models\AppStatusEvent;
use App\Models\Integration;
use App\Settings\PortalSettings;
use App\View\Admin\AppAdminStatus;
use App\View\Home\AppCardStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class StatusApiTest extends TestCase
{
    use RefreshDatabase;

    private Integration $monitor;

    private string $token;

    private App $cartera;

    protected function setUp(): void
    {
        parent::setUp();

        config(['portal.allowed_app_hosts' => ['segurex.com.co']]);
        $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00', 'America/Bogota'));

        $this->monitor = Integration::factory()->create([
            'name' => 'Monitor de disponibilidad',
            'abilities' => [Integration::CATALOG_READ, Integration::STATUS_WRITE],
        ]);
        $this->token = $this->monitor->issueToken();

        activity()->withoutLogs(function () {
            $this->cartera = App::factory()->create([
                'slug' => 'cartera',
                'name' => 'Visualizador de cartera',
                'url' => 'https://cartera.segurex.com.co',
                'login_type' => 'microsoft',
            ]);
        });
    }

    private function report(array $body, string $slug = 'cartera', ?string $token = null): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->put("/api/v1/apps/{$slug}/status", $body, ['Authorization' => 'Bearer '.($token ?? $this->token)]);
    }

    private function apiEntries()
    {
        return Activity::query()->where('log_name', 'api')->get();
    }

    // --- Reporting ------------------------------------------------------------

    public function test_a_down_report_turns_the_card_failing_with_its_message(): void
    {
        $this->report([
            'status' => 'down',
            'message' => 'SAP no responde',
            'source' => 'uptime-kuma',
            'reference' => 'INC-42',
        ])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['data' => [
                'slug' => 'cartera',
                'changed' => true,
                'status' => [
                    'current' => 'failing',
                    'maintenance' => null,
                    'reported' => [
                        'status' => 'down',
                        'message' => 'SAP no responde',
                        'reported_at' => '2026-10-06T10:00:00-05:00',
                    ],
                ],
            ]]);

        $app = $this->cartera->fresh();
        $this->assertSame('down', $app->external_status);
        $this->assertSame('SAP no responde', $app->external_status_message);
        $this->assertSame(AppCardStatus::Failing, AppCardStatus::of($app));
        $this->assertSame(AppAdminStatus::Failing, AppAdminStatus::of($app));

        $event = AppStatusEvent::query()->sole();
        $this->assertSame($app->id, $event->app_id);
        $this->assertSame($this->monitor->id, $event->integration_id);
        $this->assertSame(['down', 'SAP no responde', 'uptime-kuma', 'INC-42'], [$event->status, $event->message, $event->source, $event->reference]);
    }

    public function test_each_change_is_audited_with_the_system_as_causer(): void
    {
        $this->report(['status' => 'degraded', 'message' => 'Lento', 'reference' => 'INC-7'])->assertOk();

        $entry = $this->apiEntries()->sole();
        $this->assertSame('api.status_reported', $entry->event);
        $this->assertTrue($entry->causer->is($this->monitor));
        $this->assertTrue($entry->subject->is($this->cartera));
        $this->assertSame(['external_status' => null, 'external_status_message' => null], $entry->properties['old']);
        $this->assertSame(['external_status' => 'degraded', 'external_status_message' => 'Lento'], $entry->properties['attributes']);
        $this->assertSame('INC-7', $entry->properties['reference']);
        $this->assertStringNotContainsString($this->token, json_encode($entry->properties));
    }

    public function test_an_operational_report_brings_the_card_back(): void
    {
        $this->report(['status' => 'down'])->assertOk();
        $this->report(['status' => 'operational'])->assertOk()->assertJsonPath('data.status.current', 'active');

        $this->assertSame(AppCardStatus::Active, AppCardStatus::of($this->cartera->fresh()));
        $this->assertSame(2, AppStatusEvent::query()->count());
    }

    public function test_maintenance_wins_but_the_report_is_kept(): void
    {
        $this->cartera->update(['status' => App::STATUS_MAINTENANCE, 'maintenance_message' => 'Cierre de mes']);

        $this->report(['status' => 'down', 'message' => 'Caída'])
            ->assertOk()
            ->assertJsonPath('data.status.current', 'maintenance')
            ->assertJsonPath('data.status.reported.status', 'down');

        $app = $this->cartera->fresh();
        $this->assertSame(AppCardStatus::Maintenance, AppCardStatus::of($app));
        $this->assertSame(1, AppStatusEvent::query()->count());

        // When maintenance ends, the still-fresh report shows.
        $app->update(['status' => App::STATUS_ACTIVE]);
        $this->assertSame(AppCardStatus::Failing, AppCardStatus::of($app->fresh()));
    }

    public function test_repeating_the_same_report_changes_nothing_but_keeps_it_fresh(): void
    {
        $this->report(['status' => 'down', 'message' => 'Caída'])->assertOk();
        $updatedAt = $this->cartera->fresh()->updated_at;

        $this->travel(30)->minutes();
        $this->report(['status' => 'down', 'message' => 'Caída', 'reference' => 'otra'])
            ->assertOk()
            ->assertJsonPath('data.changed', false);

        $app = $this->cartera->fresh();
        $this->assertSame(1, AppStatusEvent::query()->count());
        $this->assertCount(1, $this->apiEntries());
        $this->assertEquals($updatedAt, $app->updated_at);
        $this->assertSame('10:30', $app->external_status_at->format('H:i'));
    }

    public function test_a_new_message_is_a_change(): void
    {
        $this->report(['status' => 'down', 'message' => 'Caída'])->assertOk();
        $this->report(['status' => 'down', 'message' => 'Sigue caída'])->assertOk()->assertJsonPath('data.changed', true);

        $this->assertSame(2, AppStatusEvent::query()->count());
        $this->assertSame('Sigue caída', $this->cartera->fresh()->external_status_message);
    }

    public function test_hidden_apps_reject_reports_like_unknown_ones(): void
    {
        // Decision 2026-10-06: hidden apps are out of the API altogether
        // (the catalog leaves them out too), and a 404 does not reveal them.
        $this->cartera->update(['status' => App::STATUS_HIDDEN]);

        $this->report(['status' => 'down'])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not found.']);

        $this->assertNull($this->cartera->fresh()->external_status);
        $this->assertSame(0, AppStatusEvent::query()->count());
        $this->assertCount(0, $this->apiEntries());
    }

    // --- Expiry -----------------------------------------------------------------

    public function test_a_failure_without_new_reports_expires_after_the_configured_hours(): void
    {
        $this->report(['status' => 'down', 'message' => 'Caída'])->assertOk();

        $this->travel(119)->minutes();
        $this->assertSame(AppCardStatus::Failing, AppCardStatus::of($this->cartera->fresh()));

        $this->travel(2)->minutes();
        $app = $this->cartera->fresh();
        $this->assertSame(AppCardStatus::Active, AppCardStatus::of($app));
        $this->assertSame(AppAdminStatus::Active, AppAdminStatus::of($app));
        $this->assertSame(1, App::query()->tap(fn ($query) => AppAdminStatus::Active->scope($query))->count());
        $this->assertSame(0, App::query()->tap(fn ($query) => AppAdminStatus::Failing->scope($query))->count());

        $this->app['auth']->forgetGuards();
        $this->get('/api/v1/apps', ['Authorization' => "Bearer {$this->token}"])
            ->assertJsonPath('data.0.status.current', 'active')
            ->assertJsonPath('data.0.status.reported', null);
    }

    public function test_the_expiry_follows_configuracion(): void
    {
        app(PortalSettings::class)->update([PortalSettings::API_STATUS_TTL => 1], null);
        $this->report(['status' => 'down'])->assertOk();

        $this->travel(61)->minutes();

        $this->assertSame(AppCardStatus::Active, AppCardStatus::of($this->cartera->fresh()));
    }

    public function test_the_same_report_after_expiry_is_a_new_change(): void
    {
        $this->report(['status' => 'down', 'message' => 'Caída'])->assertOk();
        $this->travel(3)->hours();

        $this->report(['status' => 'down', 'message' => 'Caída'])->assertOk()->assertJsonPath('data.changed', true);

        $this->assertSame(2, AppStatusEvent::query()->count());
        $this->assertSame(AppCardStatus::Failing, AppCardStatus::of($this->cartera->fresh()));
    }

    // --- Rejections ---------------------------------------------------------------

    public function test_the_body_is_validated_as_json_422(): void
    {
        $this->report(['status' => 'caido'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->report([])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->report(['status' => 'down', 'message' => str_repeat('a', 141)])->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->report(['status' => 'down', 'source' => str_repeat('a', 101), 'reference' => str_repeat('a', 101)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['source', 'reference']);

        $this->assertSame(0, AppStatusEvent::query()->count());
    }

    public function test_a_token_without_status_write_gets_403(): void
    {
        $reader = Integration::factory()->create(['abilities' => [Integration::CATALOG_READ]])->issueToken();

        $this->report(['status' => 'down'], token: $reader)->assertForbidden();
        $this->assertNull($this->cartera->fresh()->external_status);
    }

    public function test_an_unknown_app_is_a_json_404(): void
    {
        $this->report(['status' => 'down'], 'no-existe')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not found.']);
    }

    public function test_reporting_needs_a_token(): void
    {
        $this->put('/api/v1/apps/cartera/status', ['status' => 'down'])->assertUnauthorized();
    }
}
