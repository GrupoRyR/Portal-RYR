<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BaseConfigurationTest extends TestCase
{
    public function test_application_name_is_portal_ryr(): void
    {
        $this->assertSame('Portal RYR', config('app.name'));
    }

    public function test_locale_is_spanish(): void
    {
        $this->assertSame('es', config('app.locale'));
        $this->assertSame('es', config('app.fallback_locale'));
        $this->assertSame('es', $this->app->getLocale());
    }

    public function test_timezone_is_bogota(): void
    {
        $this->assertSame('America/Bogota', config('app.timezone'));
        $this->assertSame('America/Bogota', date_default_timezone_get());
    }

    public function test_health_endpoint_returns_ok(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_urls_use_https_in_production(): void
    {
        $this->app['env'] = 'production';

        (new AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('https://', url('/'));
    }

    public function test_urls_are_not_forced_to_https_outside_production(): void
    {
        (new AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('http://', url('/'));
    }

    public function test_forwarded_https_is_trusted_from_local_proxy(): void
    {
        Route::get('/_test/secure', fn (Request $request) => $request->isSecure() ? 'secure' : 'insecure');

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->get('/_test/secure')
            ->assertSeeText('secure', false)
            ->assertDontSeeText('insecure');
    }

    public function test_forwarded_https_is_ignored_from_untrusted_address(): void
    {
        Route::get('/_test/secure', fn (Request $request) => $request->isSecure() ? 'secure' : 'insecure');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->get('/_test/secure')
            ->assertSeeText('insecure');
    }
}
