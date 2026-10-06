<?php

namespace Tests\Feature\Settings;

use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\Company;
use App\Models\User;
use App\Settings\PortalSettings;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The settings saved in Configuración change the portal on the next
 * request, without editing .env.
 */
class AppliedSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->user = User::factory()->microsoft($this->company)->create();
    }

    // --- Idle timeout ---------------------------------------------------------

    /**
     * A signed-in session stored in the database, last used $idleMinutes ago,
     * like the production driver. Returns its id (the session cookie).
     */
    private function storedSession(int $idleMinutes): string
    {
        config(['session.driver' => 'database', 'session.lifetime' => 120]);

        // Each production request starts from scratch: drop the session and
        // guard the test already resolved (the activity log looks up the
        // causer when the factories save).
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app['auth']->forgetGuards();

        $id = Str::random(40);
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $this->user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => base64_encode(json_encode([
                '_token' => Str::random(40),
                // SessionGuard::getName() of the `web` guard; resolving the
                // guard here would start the session before the request.
                'login_web_'.sha1(SessionGuard::class) => $this->user->id,
            ])),
            'last_activity' => now()->subMinutes($idleMinutes)->getTimestamp(),
        ]);

        return $id;
    }

    private function homeWithSession(string $id): TestResponse
    {
        return $this->withCookie(config('session.cookie'), $id)->get(route('home'));
    }

    public function test_by_default_the_server_session_lifetime_applies(): void
    {
        $this->homeWithSession($this->storedSession(idleMinutes: 60))->assertOk();
        $this->homeWithSession($this->storedSession(idleMinutes: 300))->assertRedirect(route('login'));
    }

    public function test_a_longer_saved_timeout_keeps_an_idle_session_alive(): void
    {
        app(PortalSettings::class)->update(['idle_timeout_minutes' => 480], null);
        $id = $this->storedSession(idleMinutes: 300);

        $r = $this->homeWithSession($id);
        $r->assertOk();
        $this->assertSame(480, config('session.lifetime'));
    }

    public function test_a_shorter_saved_timeout_ends_an_idle_session(): void
    {
        app(PortalSettings::class)->update(['idle_timeout_minutes' => 240], null);
        $id = $this->storedSession(idleMinutes: 300);

        $this->homeWithSession($id)->assertRedirect(route('login'));
    }

    public function test_the_session_cookie_lasts_the_saved_timeout(): void
    {
        $this->freezeSecond();
        app(PortalSettings::class)->update(['idle_timeout_minutes' => 1440], null);

        $cookie = collect($this->actingAs($this->user)->get(route('home'))->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));

        $this->assertNotNull($cookie);
        $this->assertSame(now()->addMinutes(1440)->getTimestamp(), $cookie->getExpiresTime());
    }

    // --- Sign-out ---------------------------------------------------------------

    public function test_by_default_signing_out_leaves_microsoft_alone(): void
    {
        $this->actingAs($this->user)->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_signing_out_can_also_end_the_microsoft_session(): void
    {
        app(PortalSettings::class)->update(['microsoft_logout' => true], null);

        $location = $this->actingAs($this->user)->post(route('logout'))->assertRedirect()->headers->get('Location');

        $this->assertGuest();
        $this->assertSame(
            'https://login.microsoftonline.com/'.config('services.microsoft.tenant').'/oauth2/v2.0/logout?post_logout_redirect_uri='.urlencode(route('login')),
            $location,
        );
    }

    public function test_the_emergency_account_never_goes_to_microsoft(): void
    {
        app(PortalSettings::class)->update(['microsoft_logout' => true], null);

        $this->actingAs(User::factory()->emergency()->create())->post(route('logout'))->assertRedirect(route('login'));
    }

    // --- Allowed domains --------------------------------------------------------

    public function test_a_web_app_outside_the_saved_domains_no_longer_opens(): void
    {
        $app = App::factory()->forCompanies($this->company)->create(['url' => 'https://cartera.derivadoscol.com.co']);
        AppAccessRule::forCompany($app, $this->company);

        $this->actingAs($this->user)->get(route('apps.open', $app))->assertRedirect('https://cartera.derivadoscol.com.co');

        app(PortalSettings::class)->update(['allowed_app_hosts' => ['segurex.com.co']], null);

        $this->actingAs($this->user)->get(route('apps.open', $app))->assertNotFound();
    }
}
