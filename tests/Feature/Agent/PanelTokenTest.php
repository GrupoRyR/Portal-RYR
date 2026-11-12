<?php

namespace Tests\Feature\Agent;

use App\Agent\AgentSettings;
use App\Agent\PanelToken;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The panel token (contract §3.2): claims, signature, lifetime, and who may
 * get one. Keys and hosts here are invented.
 */
class PanelTokenTest extends TestCase
{
    use RefreshDatabase;

    private string $key;

    private Company $segurex;

    private Company $derivados;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->key = str_repeat("\x01\x02\x03\x04", 8);
        config([
            'agent.origin' => 'https://agente.example.test',
            'agent.panel_key' => base64_encode($this->key),
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'America/Bogota'));

        $this->segurex = Company::factory()->create(['slug' => 'segurex', 'domains' => ['segurex.com']]);
        $this->derivados = Company::factory()->create(['slug' => 'derivados', 'domains' => ['derivados.com']]);
    }

    private function user(?Company $company = null, array $attributes = []): User
    {
        $company ??= $this->segurex;

        // The mail and the sign-in name differ on purpose; both are at the company's own domain, the UPN in mixed case.
        return User::factory()->microsoft($company)->create($attributes + [
            'email' => 'ana.perez@'.$company->domains[0],
            'upn' => 'AEPerez@'.ucfirst($company->domains[0]),
        ]);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string} header, claims, raw signature */
    private function decode(string $token): array
    {
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        $json = fn (string $part) => json_decode(base64_decode(strtr($part, '-_', '+/')), true, 512, JSON_THROW_ON_ERROR);

        return [$json($parts[0]), $json($parts[1]), base64_decode(strtr($parts[2], '-_', '+/'))];
    }

    public function test_it_signs_the_claims_of_the_contract_in_order(): void
    {
        $user = $this->user();
        $token = PanelToken::for($user);

        [$header, $claims] = $this->decode((string) $token);

        $this->assertSame(['alg' => 'HS256', 'typ' => 'JWT'], $header);
        $this->assertSame(['iss', 'aud', 'sub', 'empresa', 'rol', 'iat', 'exp', 'jti'], array_keys($claims));
        $this->assertSame('portal-ryr', $claims['iss']);
        $this->assertSame('agente-ryr', $claims['aud']);
        $this->assertSame('aeperez@segurex.com', $claims['sub']);
        $this->assertNotSame($claims['sub'], 'ana.perez@segurex.com', 'the sub is the sign-in name, not the mail');
        $this->assertSame('SGX', $claims['empresa']);
        $this->assertSame('empleado', $claims['rol']);
        $this->assertSame(now()->getTimestamp(), $claims['iat']);
        $this->assertSame($claims['iat'] + 300, $claims['exp']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $claims['jti']);
        $this->assertLessThanOrEqual(4096, strlen((string) $token));
    }

    public function test_the_signature_is_hmac_sha256_over_header_and_payload_with_the_decoded_key(): void
    {
        $token = (string) PanelToken::for($this->user());
        [$signed, $signature] = [substr($token, 0, strrpos($token, '.')), substr($token, strrpos($token, '.') + 1)];

        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $signed, $this->key, true)), '+/', '-_'), '=');

        $this->assertSame($expected, $signature);
        $this->assertStringNotContainsString('=', $token);
        $this->assertStringNotContainsString('+', $token);
        $this->assertStringNotContainsString('/', $token);
    }

    public function test_a_tampered_payload_does_not_verify(): void
    {
        $parts = explode('.', (string) PanelToken::for($this->user()));
        $claims = $this->decode(implode('.', $parts))[1];
        $claims['rol'] = 'gerencia';
        $parts[1] = rtrim(strtr(base64_encode((string) json_encode($claims)), '+/', '-_'), '=');

        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $parts[0].'.'.$parts[1], $this->key, true)), '+/', '-_'), '=');

        $this->assertNotSame($expected, $parts[2]);
    }

    public function test_it_expires_in_five_minutes_and_each_token_has_its_own_jti(): void
    {
        $user = $this->user();
        $first = $this->decode((string) PanelToken::for($user))[1];
        $second = $this->decode((string) PanelToken::for($user))[1];

        $this->assertSame(300, $first['exp'] - $first['iat']);
        $this->assertNotSame($first['jti'], $second['jti']);

        $this->travel(301)->seconds();
        $later = $this->decode((string) PanelToken::for($user))[1];

        $this->assertGreaterThan($first['exp'], $later['iat'], 'a new token is issued after the first one expired');
        $this->assertSame($first['iat'] + 301, $later['iat']);
    }

    public function test_the_company_follows_the_account_and_is_never_all_companies(): void
    {
        $sgx = $this->decode((string) PanelToken::for($this->user($this->segurex)))[1];
        $sdc = $this->decode((string) PanelToken::for($this->user($this->derivados)))[1];

        $this->assertSame(['SGX', 'aeperez@segurex.com'], [$sgx['empresa'], $sgx['sub']]);
        $this->assertSame(['SDC', 'aeperez@derivados.com'], [$sdc['empresa'], $sdc['sub']]);
    }

    private const GROUP = 'aaaaaaaa-0000-4000-8000-000000000001';

    private function manager(): User
    {
        $this->segurex->forceFill(['management_group_id' => self::GROUP])->save();
        $user = $this->user($this->segurex->fresh());
        $user->assignRole('management');
        $user->syncGroups([['id' => self::GROUP, 'display_name' => 'APP-PortalGerencia', 'security_group' => true]]);

        return $user->fresh();
    }

    public function test_the_management_group_gets_gerencia_and_everyone_else_empleado(): void
    {
        $manager = $this->manager();
        $admin = $this->user(null, ['upn' => 'admin@segurex.com']);
        $admin->assignRole('portal_admin');
        $editor = $this->user(null, ['upn' => 'editor@segurex.com']);
        $editor->assignRole('announcements_editor');

        $this->assertSame('gerencia', $this->decode((string) PanelToken::for($manager))[1]['rol']);
        $this->assertSame('empleado', $this->decode((string) PanelToken::for($admin))[1]['rol']);
        $this->assertSame('empleado', $this->decode((string) PanelToken::for($editor))[1]['rol']);
    }

    public function test_gerencia_follows_the_live_management_check_not_just_the_cached_role(): void
    {
        $manager = $this->manager();
        $this->assertSame('gerencia', $this->decode((string) PanelToken::for($manager))[1]['rol']);

        // Clearing the group closes the management panel at once; the token follows.
        $this->segurex->forceFill(['management_group_id' => null])->save();
        $this->assertTrue($manager->fresh()->hasRole('management'), 'the cached role is still there');
        $this->assertSame('empleado', $this->decode((string) PanelToken::for($manager->fresh()))[1]['rol']);

        // The role without membership of the group is not enough either.
        $this->segurex->forceFill(['management_group_id' => self::GROUP])->save();
        $manager->groups()->delete();
        $this->assertSame('empleado', $this->decode((string) PanelToken::for($manager->fresh()))[1]['rol']);
    }

    public function test_an_address_at_another_companys_domain_is_never_signed_as_an_identity(): void
    {
        // The address is an attribute the tenant's admins control: an SDC account claiming a Segurex mailbox.
        $forged = $this->user($this->derivados, ['upn' => 'ceo@segurex.com']);

        $this->assertNull(PanelToken::for($forged));
        $this->assertNull(PanelToken::for($this->user($this->segurex, ['upn' => 'x@segurex.com.evil.test'])));
        $this->assertNull(PanelToken::for($this->user($this->segurex, ['upn' => 'x@sub.segurex.com'])));
        $this->assertNull(PanelToken::for($this->user($this->segurex, ['upn' => 'no-at-sign'])));
    }

    public function test_emergency_and_guest_accounts_get_no_token(): void
    {
        $this->assertNull(PanelToken::for(User::factory()->emergency()->create()));
        $this->assertNull(PanelToken::for($this->user(null, ['is_guest' => true])));
    }

    public function test_an_account_without_company_or_with_an_unknown_company_gets_no_token(): void
    {
        $this->assertNull(PanelToken::for(User::factory()->create(['company_id' => null, 'email' => 'x@segurex.com'])));

        $other = Company::factory()->create(['slug' => 'tercera']);
        $this->assertNull(PanelToken::for($this->user($other)));
    }

    public function test_an_inactive_company_gets_no_token(): void
    {
        $this->assertNull(PanelToken::for($this->user(Company::factory()->inactive()->create(['slug' => 'segurex-vieja']))));

        $this->segurex->forceFill(['is_active' => false])->save();
        $this->assertNull(PanelToken::for($this->user($this->segurex->fresh(), ['email' => 'b@segurex.com'])));
    }

    public function test_an_account_without_a_sign_in_name_gets_no_token_even_with_a_mail(): void
    {
        // An account that has not signed in since the UPN was kept: no token until it does.
        $this->assertNull(PanelToken::for($this->user(null, ['upn' => null])));
        $this->assertNull(PanelToken::for($this->user(null, ['upn' => ''])));
        $this->assertNull(PanelToken::for($this->user(null, ['upn' => '  '])));
    }

    public function test_the_mail_is_never_used_for_the_subject(): void
    {
        // Same domain, different mail: only the UPN counts, and a mail at a foreign domain does not matter.
        $user = $this->user(null, ['email' => 'someone.else@elsewhere.test']);

        $this->assertSame('aeperez@segurex.com', $this->decode((string) PanelToken::for($user))[1]['sub']);
    }

    public function test_without_a_valid_key_or_origin_nothing_is_signed(): void
    {
        $user = $this->user();

        foreach ([
            ['agent.panel_key', null],
            ['agent.panel_key', ''],
            ['agent.panel_key', 'not base64 !!'],
            ['agent.panel_key', base64_encode(str_repeat('k', 31))],
            ['agent.origin', null],
            ['agent.origin', 'http://agente.example.test'],
            ['agent.origin', 'https://agente.example.test/panel'],
            ['agent.origin', 'https://user@agente.example.test'],
        ] as [$name, $value]) {
            config([
                'agent.origin' => 'https://agente.example.test',
                'agent.panel_key' => base64_encode($this->key),
                $name => $value,
            ]);

            $this->assertNull(PanelToken::for($user), $name.'='.var_export($value, true));
        }
    }

    public function test_the_origin_is_normalised_and_the_key_is_decoded_strictly(): void
    {
        config(['agent.origin' => ' HTTPS://Agente.Example.Test:8443 ']);
        $this->assertSame('https://agente.example.test:8443', AgentSettings::origin());

        config(['agent.panel_key' => base64_encode(str_repeat('k', 32))]);
        $this->assertSame(str_repeat('k', 32), AgentSettings::key());
        $this->assertTrue(AgentSettings::active());
    }
}
