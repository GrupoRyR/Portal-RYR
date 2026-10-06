<?php

namespace Tests\Feature\Emergency;

use App\Auth\EmergencyTwoFactor;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class EmergencyAccountCommandTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'emergencia@segurex.com';

    private const PASSWORD = 'una-clave-muy-larga-2026';

    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private const OTHER_SECRET = 'KRSXG5CTMVRXEZLUKRSXG5CTMVRXEZLU';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function useSecret(string $secret): void
    {
        // A real instance (constructor run) with only the secret fixed, so
        // the test can compute the codes the account will accept.
        $twoFactor = Mockery::mock(EmergencyTwoFactor::class, [new Google2FA])->makePartial();
        $twoFactor->shouldReceive('generateSecret')->andReturn($secret);
        $this->instance(EmergencyTwoFactor::class, $twoFactor);
    }

    private function codeFor(string $secret): string
    {
        return (new Google2FA)->getCurrentOtp($secret);
    }

    private function wrongCodeFor(string $secret): string
    {
        return str_pad((string) (((int) $this->codeFor($secret) + 500000) % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public function test_it_creates_and_confirms_an_emergency_account(): void
    {
        $this->useSecret(self::SECRET);

        $this->artisan('portal:emergency-account', ['email' => 'Emergencia@Segurex.com'])
            ->expectsQuestion('Contraseña (mínimo 16 caracteres)', self::PASSWORD)
            ->expectsQuestion('Repite la contraseña', self::PASSWORD)
            ->expectsOutputToContain('otpauth://totp/Portal%20RYR:emergencia%40segurex.com?secret='.self::SECRET)
            ->expectsOutputToContain(self::SECRET)
            ->expectsQuestion('Código de 6 dígitos de la aplicación', $this->codeFor(self::SECRET))
            ->assertSuccessful();

        $user = User::sole();
        $this->assertSame(self::EMAIL, $user->email);
        $this->assertTrue($user->is_emergency);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertSame(self::SECRET, $user->two_factor_secret);
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertNotNull($user->two_factor_last_timestep);
        $this->assertNull($user->azure_oid);
        $this->assertNull($user->tenant_id);
        $this->assertNull($user->company_id);
        $this->assertTrue($user->hasRole('super_admin'));
        $this->assertArrayNotHasKey('two_factor_secret', $user->toArray());
        $this->assertNotSame(self::SECRET, $user->getRawOriginal('two_factor_secret'));
    }

    public function test_a_short_password_is_rejected_and_nothing_is_saved(): void
    {
        $this->artisan('portal:emergency-account', ['email' => self::EMAIL])
            ->expectsQuestion('Contraseña (mínimo 16 caracteres)', 'corta')
            ->expectsQuestion('Repite la contraseña', 'corta')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_mismatched_passwords_are_rejected_and_nothing_is_saved(): void
    {
        $this->artisan('portal:emergency-account', ['email' => self::EMAIL])
            ->expectsQuestion('Contraseña (mínimo 16 caracteres)', self::PASSWORD)
            ->expectsQuestion('Repite la contraseña', self::PASSWORD.'x')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_wrong_confirmation_code_saves_nothing(): void
    {
        $this->useSecret(self::SECRET);

        $this->artisan('portal:emergency-account', ['email' => self::EMAIL])
            ->expectsQuestion('Contraseña (mínimo 16 caracteres)', self::PASSWORD)
            ->expectsQuestion('Repite la contraseña', self::PASSWORD)
            ->expectsQuestion('Código de 6 dígitos de la aplicación', $this->wrongCodeFor(self::SECRET))
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_wrong_confirmation_code_keeps_an_existing_account_unchanged(): void
    {
        $user = User::factory()->emergency(self::SECRET)->create(['email' => self::EMAIL]);
        $before = $user->fresh()->getAttributes();
        $this->useSecret(self::OTHER_SECRET);

        $this->artisan('portal:emergency-account', ['email' => self::EMAIL, '--reset-2fa' => true])
            ->expectsQuestion('Código de 6 dígitos de la aplicación', $this->wrongCodeFor(self::OTHER_SECRET))
            ->assertFailed();

        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_reset_2fa_replaces_only_the_secret(): void
    {
        $user = User::factory()->emergency(self::SECRET)->create(['email' => self::EMAIL]);
        $hash = $user->password;
        $this->useSecret(self::OTHER_SECRET);

        $this->artisan('portal:emergency-account', ['email' => self::EMAIL, '--reset-2fa' => true])
            ->expectsOutputToContain(self::OTHER_SECRET)
            ->expectsQuestion('Código de 6 dígitos de la aplicación', $this->codeFor(self::OTHER_SECRET))
            ->assertSuccessful();

        $user->refresh();
        $this->assertSame(self::OTHER_SECRET, $user->two_factor_secret);
        $this->assertSame($hash, $user->password);
    }

    public function test_disable_clears_credentials_and_role_but_keeps_the_row(): void
    {
        $user = User::factory()->emergency(self::SECRET)->create(['email' => self::EMAIL]);
        $user->assignRole('super_admin');

        $this->artisan('portal:emergency-account', ['email' => self::EMAIL, '--disable' => true])
            ->assertSuccessful();

        $user->refresh();
        $this->assertDatabaseCount('users', 1);
        $this->assertNull($user->password);
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertNull($user->two_factor_last_timestep);
        $this->assertFalse($user->hasRole('super_admin'));
    }

    public function test_options_require_an_existing_emergency_account(): void
    {
        $this->artisan('portal:emergency-account', ['email' => self::EMAIL, '--disable' => true])->assertFailed();
        $this->artisan('portal:emergency-account', ['email' => self::EMAIL, '--reset-2fa' => true])->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_an_email_of_a_microsoft_user_is_refused(): void
    {
        $microsoft = User::factory()->microsoft(Company::factory()->create())->create(['email' => self::EMAIL]);
        $before = $microsoft->fresh()->getAttributes();

        $this->artisan('portal:emergency-account', ['email' => self::EMAIL])->assertFailed();
        $this->artisan('portal:emergency-account', ['email' => self::EMAIL, '--disable' => true])->assertFailed();

        $this->assertDatabaseCount('users', 1);
        $this->assertSame($before, $microsoft->fresh()->getAttributes());
    }

    public function test_an_email_of_another_local_user_is_refused(): void
    {
        User::factory()->create(['email' => self::EMAIL]);

        $this->artisan('portal:emergency-account', ['email' => self::EMAIL])->assertFailed();

        $this->assertSame(0, User::where('is_emergency', true)->count());
    }
}
