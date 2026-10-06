<?php

namespace Tests\Feature\Emergency;

use App\Auth\LoginFailed;
use App\Auth\MicrosoftIdentity;
use App\Auth\MicrosoftLogin;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class EmergencyLoginTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'emergencia@segurex.com';

    private const PASSWORD = 'una-clave-muy-larga-2026';

    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private const GENERIC = 'Credenciales inválidas.';

    private const PATH = '/acceso-emergencia';

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['logging.default' => 'null']);
        Log::listen(function (MessageLogged $event) {
            $this->logged[] = $event;
        });
    }

    private function emergencyUser(array $attributes = []): User
    {
        return User::factory()->emergency(self::SECRET)->create(array_merge([
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ], $attributes));
    }

    private function code(): string
    {
        return (new Google2FA)->getCurrentOtp(self::SECRET);
    }

    private function wrongCode(): string
    {
        return str_pad((string) (((int) $this->code() + 500000) % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function postCredentials(string $email = self::EMAIL, string $password = self::PASSWORD): TestResponse
    {
        return $this->post(self::PATH, ['email' => $email, 'password' => $password]);
    }

    private function postCode(string $code): TestResponse
    {
        return $this->post(self::PATH.'/codigo', ['code' => $code]);
    }

    private function assertGenericFailure(TestResponse $response): void
    {
        $response->assertRedirect(self::PATH)->assertSessionHasErrors(['emergency' => self::GENERIC]);
        $this->assertGuest();
    }

    public function test_the_forms_are_reachable_for_guests_only(): void
    {
        $this->get(self::PATH)->assertOk()->assertSee('Acceso de emergencia');
        $this->actingAs(User::factory()->create())->get(self::PATH)->assertRedirect('/');
    }

    public function test_the_path_comes_from_configuration(): void
    {
        $this->assertSame('acceso-emergencia', config('portal.emergency_path'));
        $this->assertSame(url(self::PATH), route('emergency.login'));
        $this->assertSame(url(self::PATH.'/codigo'), route('emergency.code'));
    }

    public function test_route_names_are_unique_so_routes_can_be_cached(): void
    {
        $names = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter();

        $this->assertSame($names->count(), $names->unique()->count(), $names->duplicates()->implode(', '));
    }

    public function test_the_code_form_requires_a_pending_step(): void
    {
        $this->get(self::PATH.'/codigo')->assertRedirect(self::PATH);
    }

    public function test_the_login_page_does_not_link_to_the_emergency_access(): void
    {
        $this->get('/login')->assertDontSee('acceso-emergencia');
    }

    public function test_a_valid_password_and_code_sign_in(): void
    {
        $user = $this->emergencyUser();

        $this->postCredentials()->assertRedirect(self::PATH.'/codigo');
        $this->assertGuest();
        $this->get(self::PATH.'/codigo')->assertOk()->assertSee('Código');

        $this->postCode($this->code())->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->assertNull(session('emergency.pending'));
        $this->assertNotNull($user->fresh()->two_factor_last_timestep);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_the_session_is_regenerated_on_success(): void
    {
        $this->emergencyUser();
        $this->postCredentials();
        $before = session()->getId();

        $this->postCode($this->code());

        $this->assertNotSame($before, session()->getId());
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $this->emergencyUser();

        $this->assertGenericFailure($this->postCredentials(password: 'otra-clave-muy-larga-2026'));
        $this->assertNull(session('emergency.pending'));
    }

    public function test_an_unknown_email_is_rejected_with_the_same_message(): void
    {
        $this->emergencyUser();

        $this->assertGenericFailure($this->postCredentials(email: 'nadie@segurex.com'));
    }

    public function test_a_wrong_code_is_rejected(): void
    {
        $this->emergencyUser();
        $this->postCredentials();

        $this->assertGenericFailure($this->postCode($this->wrongCode()));
    }

    public function test_a_replayed_code_is_rejected(): void
    {
        $this->emergencyUser();
        $this->postCredentials();
        $code = $this->code();
        $this->postCode($code)->assertRedirect('/');
        $this->post('/logout');

        $this->postCredentials()->assertRedirect(self::PATH.'/codigo');

        $this->assertGenericFailure($this->postCode($code));
    }

    public function test_an_expired_pending_step_is_rejected(): void
    {
        $this->emergencyUser();
        $this->postCredentials();

        $this->travel(6)->minutes();

        $this->assertGenericFailure($this->postCode($this->code()));
        $this->assertNull(session('emergency.pending'));
    }

    public function test_a_code_without_a_pending_step_is_rejected(): void
    {
        $this->emergencyUser();

        $this->assertGenericFailure($this->postCode($this->code()));
    }

    public function test_a_non_emergency_local_user_is_rejected(): void
    {
        User::factory()->create(['email' => self::EMAIL, 'password' => self::PASSWORD]);

        $this->assertGenericFailure($this->postCredentials());
    }

    public function test_an_emergency_user_without_confirmed_two_factor_is_rejected(): void
    {
        $this->emergencyUser(['two_factor_confirmed_at' => null]);

        $this->assertGenericFailure($this->postCredentials());
    }

    public function test_a_disabled_emergency_user_is_rejected(): void
    {
        $this->emergencyUser(['password' => null]);

        $this->assertGenericFailure($this->postCredentials());
    }

    public function test_a_microsoft_user_can_never_pass_even_with_a_password_and_two_factor(): void
    {
        $this->emergencyUser([
            'azure_oid' => '11111111-1111-1111-1111-111111111111',
            'tenant_id' => '97cd024c-cd7a-419b-b385-f47b5f5816c8',
        ]);

        $this->assertGenericFailure($this->postCredentials());
    }

    public function test_an_emergency_user_can_never_sign_in_through_microsoft(): void
    {
        $company = Company::factory()->create();
        $this->emergencyUser([
            'azure_oid' => '11111111-1111-1111-1111-111111111111',
            'tenant_id' => $company->tenant_id,
        ]);

        $this->expectException(LoginFailed::class);

        $this->app->make(MicrosoftLogin::class)->handle(new MicrosoftIdentity(
            $company->tenant_id,
            '11111111-1111-1111-1111-111111111111',
            self::EMAIL,
            'Emergencia',
            'token',
            $company->tenant_id,
        ));
    }

    public function test_credentials_are_throttled_and_the_lockout_stays_generic(): void
    {
        $this->emergencyUser();

        for ($i = 0; $i < 5; $i++) {
            $this->postCredentials(password: 'otra-clave-muy-larga-2026');
        }

        $this->assertGenericFailure($this->postCredentials());
        $this->assertNull(session('emergency.pending'));
    }

    public function test_codes_are_throttled_and_the_lockout_stays_generic(): void
    {
        $this->emergencyUser();
        $this->postCredentials();

        for ($i = 0; $i < 5; $i++) {
            $this->postCode($this->wrongCode());
        }

        $this->assertGenericFailure($this->postCode($this->code()));
    }

    public function test_attempts_are_logged_without_secrets(): void
    {
        $this->emergencyUser();
        $code = $this->code();

        $this->postCredentials(password: 'otra-clave-muy-larga-2026');
        $this->postCredentials();
        $this->postCode($this->wrongCode());
        $this->withHeader('User-Agent', 'PruebaNavegador/1.0')->postCode($code);

        $levels = collect($this->logged)->pluck('level')->all();
        $this->assertContains('warning', $levels);
        $this->assertContains('notice', $levels);

        $success = collect($this->logged)->firstWhere('level', 'notice');
        $this->assertSame(self::EMAIL, $success->context['email']);
        $this->assertArrayHasKey('ip', $success->context);
        $this->assertSame('PruebaNavegador/1.0', $success->context['user_agent']);

        $dump = json_encode(collect($this->logged)->map(fn (MessageLogged $e) => [$e->message, $e->context])->all());
        $this->assertStringNotContainsString(self::PASSWORD, $dump);
        $this->assertStringNotContainsString('otra-clave-muy-larga-2026', $dump);
        $this->assertStringNotContainsString(self::SECRET, $dump);
        $this->assertStringNotContainsString($code, $dump);
        $this->assertStringNotContainsString($this->wrongCode(), $dump);
    }

    // --- Security events (Auditoría) ---------------------------------------

    public function test_sign_ins_and_failures_are_security_events_without_secrets(): void
    {
        $user = $this->emergencyUser();
        $code = $this->code();

        $this->postCredentials(password: 'otra-clave-muy-larga-2026');
        $this->postCredentials('Nadie@Segurex.com', 'algo');
        $this->postCredentials();
        $this->postCode($this->wrongCode());
        $this->postCode($code)->assertRedirect('/');

        $entries = Activity::query()->where('log_name', 'security')->orderBy('id')->get();

        $this->assertSame(
            [
                ['login.emergency_failed', self::EMAIL, 'credentials'],
                ['login.emergency_failed', 'nadie@segurex.com', 'credentials'],
                ['login.emergency_failed', self::EMAIL, 'code'],
                ['login.emergency', self::EMAIL, 'code'],
            ],
            $entries->map(fn (Activity $entry) => [$entry->event, $entry->properties['email'], $entry->properties['step']])->all(),
        );
        $this->assertNull($entries[0]->causer_id);
        $this->assertTrue($entries[3]->causer->is($user));

        $dump = json_encode($entries->pluck('properties')->all());
        $this->assertStringNotContainsString(self::PASSWORD, $dump);
        $this->assertStringNotContainsString('otra-clave-muy-larga-2026', $dump);
        $this->assertStringNotContainsString(self::SECRET, $dump);
        $this->assertStringNotContainsString($code, $dump);
        $this->assertStringNotContainsString($this->wrongCode(), $dump);
    }
}
