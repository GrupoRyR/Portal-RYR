<?php

namespace Tests\Feature\Settings;

use App\Audit\AuditPresenter;
use App\Catalog\AppUrlPolicy;
use App\Models\User;
use App\Settings\PortalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class PortalSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function settings(): PortalSettings
    {
        return app(PortalSettings::class);
    }

    public function test_defaults_come_from_the_configuration(): void
    {
        config([
            'portal.support_url' => null,
            'session.lifetime' => 120,
            'portal.microsoft_logout' => false,
            'portal.allowed_app_hosts' => ['segurex.com.co', 'sgx-*.vercel.app'],
            'portal.api_status_ttl_hours' => 2,
        ]);

        $this->assertSame([
            'support_url' => null,
            'idle_timeout_minutes' => 120,
            'microsoft_logout' => false,
            'allowed_app_hosts' => ['segurex.com.co', 'sgx-*.vercel.app'],
            'api_status_ttl_hours' => 2,
        ], $this->settings()->all());
        $this->assertSame(0, DB::table('portal_settings')->count());
    }

    public function test_saved_values_replace_the_defaults_and_are_cached(): void
    {
        $this->settings()->update([
            'support_url' => 'https://soporte.segurex.com.co/nuevo',
            'idle_timeout_minutes' => 480,
            'microsoft_logout' => true,
            'allowed_app_hosts' => ['segurex.com.co'],
            'api_status_ttl_hours' => 4,
        ], null);

        $this->assertSame('https://soporte.segurex.com.co/nuevo', $this->settings()->supportUrl());
        $this->assertSame(480, $this->settings()->idleTimeoutMinutes());
        $this->assertTrue($this->settings()->signsOutOfMicrosoft());
        $this->assertSame(['segurex.com.co'], $this->settings()->allowedAppHosts());
        $this->assertSame(4, $this->settings()->apiStatusTtlHours());

        // A fresh instance reads the cache, not the table.
        DB::table('portal_settings')->delete();
        $this->assertSame(480, (new PortalSettings)->idleTimeoutMinutes());

        Cache::forget(PortalSettings::CACHE_KEY);
        $this->assertSame(config('session.lifetime'), (new PortalSettings)->idleTimeoutMinutes());
    }

    public function test_only_changed_values_are_stored(): void
    {
        $this->settings()->update($this->settings()->all(), null);
        $this->assertSame(0, DB::table('portal_settings')->count());
        $this->assertSame(0, Activity::count());

        $this->settings()->update([...$this->settings()->all(), 'api_status_ttl_hours' => 1], null);
        $this->assertSame(['api_status_ttl_hours'], DB::table('portal_settings')->pluck('key')->all());
    }

    public function test_an_empty_support_url_is_stored_as_null(): void
    {
        $this->settings()->update(['support_url' => 'https://soporte.segurex.com.co'], null);
        $this->settings()->update(['support_url' => '  '], null);

        $this->assertNull($this->settings()->supportUrl());
    }

    public function test_unknown_keys_are_ignored(): void
    {
        $this->settings()->update(['evil' => 'x'], null);

        $this->assertSame(0, DB::table('portal_settings')->count());
        $this->assertArrayNotHasKey('evil', $this->settings()->all());
    }

    public function test_every_change_is_audited_with_before_and_after(): void
    {
        $admin = User::factory()->create(['name' => 'Jhon Castro']);
        config(['session.lifetime' => 120]);

        $this->settings()->update([
            'idle_timeout_minutes' => 480,
            'microsoft_logout' => true,
            'allowed_app_hosts' => ['segurex.com.co'],
        ], $admin);

        $activity = Activity::query()->sole();
        $this->assertSame(PortalSettings::AUDIT_LOG, $activity->log_name);
        $this->assertSame('settings.updated', $activity->event);
        $this->assertTrue($activity->causer->is($admin));
        $this->assertSame(120, $activity->properties['old']['idle_timeout_minutes']);
        $this->assertSame(480, $activity->properties['attributes']['idle_timeout_minutes']);

        $entry = (new AuditPresenter)->activity($activity);
        $this->assertSame('Cambio', $entry->categoryLabel());
        $this->assertSame('Cambió la configuración del portal', $entry->what);
        $labels = array_column($entry->changes, 'label');
        $this->assertContains('Cierre por inactividad (minutos)', $labels);
        $this->assertContains('Cerrar también Microsoft', $labels);
        $this->assertContains('Dominios permitidos', $labels);
        $this->assertContains(['label' => 'Cerrar también Microsoft', 'old' => 'No', 'new' => 'Sí'], $entry->changes);
    }

    public function test_the_url_policy_follows_the_saved_domains(): void
    {
        $this->assertTrue(AppUrlPolicy::current()->allows('https://pedidos.segurex.com.co'));

        $this->settings()->update(['allowed_app_hosts' => ['derivadoscol.com.co']], null);

        $this->assertFalse(AppUrlPolicy::current()->allows('https://pedidos.segurex.com.co'));
        $this->assertTrue(AppUrlPolicy::current()->allows('https://cartera.derivadoscol.com.co'));
    }
}
