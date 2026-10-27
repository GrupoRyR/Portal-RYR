<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\Auth\LogoutController;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->user = User::factory()->microsoft(Company::factory()->create())->create();
    }

    private function assertSecurityHeaders(TestResponse $response): TestResponse
    {
        return $response
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'")
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_the_sign_in_page_sends_them(): void
    {
        $this->assertSecurityHeaders($this->get(route('login')))->assertOk();
    }

    public function test_the_home_screen_sends_them(): void
    {
        $this->assertSecurityHeaders($this->actingAs($this->user)->get('/'))->assertOk();
    }

    public function test_admin_pages_send_them(): void
    {
        $this->user->assignRole('super_admin');

        $this->assertSecurityHeaders($this->actingAs($this->user)->get(route('admin.companies.index')))->assertOk();
    }

    public function test_pages_outside_the_routes_send_them(): void
    {
        $this->assertSecurityHeaders($this->get('/no-existe'))->assertNotFound();
        $this->assertSecurityHeaders($this->getJson('/api/v1/apps'))->assertUnauthorized();
    }

    public function test_the_installable_app_keeps_working(): void
    {
        $this->assertSecurityHeaders($this->get(route('pwa.service-worker')))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/javascript; charset=utf-8');

        $this->assertSecurityHeaders($this->actingAs($this->user)->get(route('pwa.manifest')))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json');
    }

    public function test_signing_out_still_clears_the_browser_data(): void
    {
        $this->assertSecurityHeaders($this->actingAs($this->user)->post(route('logout')))
            ->assertRedirect(route('login'))
            ->assertHeader('Clear-Site-Data', LogoutController::CLEAR_SITE_DATA);
    }

    public function test_https_responses_ask_browsers_to_stay_on_https(): void
    {
        $this->get('https://localhost/login')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_https_through_the_local_proxy_counts_as_https(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->get('http://localhost/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    /**
     * Plain HTTP never gets HSTS (browsers ignore it there) and is not
     * redirected by the app: the hosting's Force HTTPS Redirect does that.
     */
    public function test_plain_http_gets_no_hsts_and_no_redirect(): void
    {
        $this->get('http://localhost/login')->assertOk()->assertHeaderMissing('Strict-Transport-Security');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->get('http://localhost/login')
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_a_policy_set_by_the_response_itself_is_kept(): void
    {
        $this->app['router']->get('/_pruebas/csp', fn () => response('x')->header('Content-Security-Policy', 'sandbox'));

        $this->get('/_pruebas/csp')->assertHeader('Content-Security-Policy', 'sandbox');
    }
}
