<?php

namespace Tests\Feature\Agent;

use App\Assistant\AssistantSwitch;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The agent's floating button (<x-agent-panel />, which replaced the old
 * «Asistente» button) and the headers around it. Hosts and keys are invented.
 */
class AgentPanelTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'https://agente.example.test';

    private const LINK = '//a[@data-agente-panel]';

    private string $key;

    private Company $segurex;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->key = str_repeat("\x09\x08\x07\x06", 8);
        config(['agent.origin' => self::ORIGIN, 'agent.panel_key' => base64_encode($this->key)]);
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'America/Bogota'));

        $this->segurex = Company::factory()->create(['slug' => 'segurex', 'domains' => ['segurex.com']]);
        $this->user = User::factory()->microsoft($this->segurex)->create(['email' => 'ana.mail@segurex.com', 'upn' => 'ana@segurex.com']);
    }

    private function page(string $path, ?User $user = null): DOMXPath
    {
        $html = $this->actingAs($user ?? $this->user)->get($path)->assertOk()->getContent();

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);

        return new DOMXPath($dom);
    }

    public function test_it_floats_on_the_home_screen_and_on_secondary_pages(): void
    {
        foreach (['/', route('announcements.index', absolute: false), route('events.index', absolute: false), route('people.index', absolute: false), route('account.show', absolute: false)] as $path) {
            $this->assertSame(1, $this->page($path)->query(self::LINK)->length, $path);
        }
    }

    public function test_the_link_carries_no_token_and_the_script_names_the_token_route(): void
    {
        $dom = $this->page('/');
        $href = $dom->query(self::LINK)->item(0)->getAttribute('href');

        $this->assertSame(self::ORIGIN.'/panel/abrir', $href);
        $html = $this->actingAs($this->user)->get('/')->getContent();
        $this->assertStringNotContainsString('#t=', $html);
        $this->assertStringNotContainsString('?t=', $html);
        $this->assertDoesNotMatchRegularExpression('~eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+~', $html);

        $script = $dom->query('//section[@data-agent-present]/script')->item(0);
        // A path from the route helper, never an absolute URL.
        $this->assertSame('/agente/token', $script->getAttribute('data-agente-token-url'));
        $this->assertSame(route('agent.token', absolute: false), $script->getAttribute('data-agente-token-url'));
    }

    public function test_the_page_carries_the_csrf_token_the_launcher_sends(): void
    {
        $dom = $this->page('/');

        $this->assertSame(1, $dom->query('//meta[@name="csrf-token"]')->length);
        $this->assertNotSame('', $dom->query('//meta[@name="csrf-token"]')->item(0)->getAttribute('content'));
    }

    public function test_the_launcher_is_our_own_copy_and_takes_the_origin_from_an_attribute(): void
    {
        $script = $this->page('/')->query('//section[@data-agent-present]/script')->item(0);

        $this->assertStringStartsWith('http://localhost/js/agente-lanzador.js?v=', $script->getAttribute('src'));
        $this->assertSame(self::ORIGIN, $script->getAttribute('data-agente-origen'));
        $this->assertTrue($script->hasAttribute('defer'));

        $link = $this->page('/')->query(self::LINK)->item(0);
        $this->assertSame('_blank', $link->getAttribute('target'));
        $this->assertSame('noopener', $link->getAttribute('rel'));
        $this->assertSame('segurex', $link->getAttribute('data-agente-marca'));
    }

    public function test_the_service_worker_leaves_it_out_of_the_saved_home_page(): void
    {
        $anchor = $this->page('/')->query('//section[@data-agent-present]')->item(0);

        $this->assertTrue($anchor->hasAttribute('data-offline-omit'));
        // The service worker's own pattern (resources/js/sw.js) removes the whole block.
        $html = $this->actingAs($this->user)->get('/')->getContent();
        $stripped = preg_replace('~<(section|li)\b[^>]*\bdata-offline-omit\b[^>]*>[\s\S]*?</\1>\s*~', '', $html);
        $this->assertStringNotContainsString('data-agente-token-url', $stripped);
        $this->assertStringNotContainsString('data-agente-panel', $stripped);
    }

    public function test_the_page_never_loads_a_script_from_the_agent(): void
    {
        $html = $this->actingAs($this->user)->get('/')->getContent();

        $this->assertDoesNotMatchRegularExpression('~<script[^>]+src="'.preg_quote(self::ORIGIN, '~').'~', $html);
    }

    public function test_it_replaces_the_old_assistant_button(): void
    {
        $html = $this->actingAs($this->user)->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-assistant-fab', $html);
        $this->assertStringNotContainsString('assistant-panel', $html);
        $this->assertStringNotContainsString('g-fab', $html);
    }

    public function test_the_brand_follows_the_company(): void
    {
        $derivados = Company::factory()->create(['slug' => 'derivados', 'domains' => ['derivados.com']]);
        $other = User::factory()->microsoft($derivados)->create(['email' => 'luis.mail@derivados.com', 'upn' => 'luis@derivados.com']);

        $this->assertSame('derivados', $this->page('/', $other)->query(self::LINK)->item(0)->getAttribute('data-agente-marca'));
    }

    public function test_it_is_absent_on_the_sign_in_the_error_pages_and_the_drafts_page(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('data-agente-panel', false);
        $this->actingAs($this->user)->get('/admin')->assertForbidden()->assertDontSee('data-agente-panel', false);

        // The drafts page is live only while the assistant is on; the button stays off it.
        config(['assistant.enabled' => true]);
        DB::table('portal_settings')->insert(['key' => AssistantSwitch::SETTING, 'value' => 'true']);
        $this->assertSame(0, $this->page(route('assistant.show', absolute: false))->query(self::LINK)->length);
    }

    public function test_a_glass_page_shows_it_only_when_it_opts_in(): void
    {
        $this->actingAs($this->user);

        $this->assertStringNotContainsString('data-agente-panel', (string) $this->blade('<x-layouts.app glass><main>Página</main></x-layouts.app>'));
        $this->assertStringContainsString('data-agente-panel', (string) $this->blade('<x-layouts.app glass assistant><main>Página</main></x-layouts.app>'));
    }

    public function test_no_token_and_no_button_for_accounts_the_agent_does_not_accept(): void
    {
        $guest = User::factory()->microsoft($this->segurex)->create(['email' => 'g@example.test', 'is_guest' => true]);
        $unmapped = User::factory()->microsoft(Company::factory()->create(['slug' => 'tercera']))->create(['email' => 't@example.test']);

        foreach ([$guest, $unmapped] as $account) {
            $html = $this->actingAs($account)->get('/')->assertOk()->getContent();
            $this->assertStringNotContainsString('data-agente-panel', $html);
            $this->assertStringNotContainsString('agente-lanzador', $html);
            $this->assertStringNotContainsString('data-agente-token-url', $html);
        }
    }

    public function test_nothing_is_drawn_while_the_key_or_origin_is_missing(): void
    {
        foreach ([['agent.panel_key', null], ['agent.origin', null], ['agent.origin', 'http://agente.example.test']] as [$name, $value]) {
            config(['agent.origin' => self::ORIGIN, 'agent.panel_key' => base64_encode($this->key), $name => $value]);

            $this->actingAs($this->user)->get('/')->assertOk()->assertDontSee('data-agente-panel', false);
        }
    }

    public function test_the_old_assistant_button_stays_while_the_connection_is_not_configured(): void
    {
        $this->turnTheAssistantOn();

        foreach ([['agent.panel_key', null], ['agent.panel_key', base64_encode('short')], ['agent.origin', null], ['agent.origin', 'http://agente.example.test']] as [$name, $value]) {
            config(['agent.origin' => self::ORIGIN, 'agent.panel_key' => base64_encode($this->key), $name => $value]);

            $dom = $this->page('/');
            $this->assertSame(1, $dom->query('//a[@data-assistant-fab]')->length, $name.'='.var_export($value, true));
            $this->assertSame(0, $dom->query(self::LINK)->length, $name);
        }
    }

    public function test_an_account_the_agent_accepts_sees_only_the_agent_button_even_with_the_assistant_on(): void
    {
        $this->turnTheAssistantOn();

        $dom = $this->page('/');
        $this->assertSame(1, $dom->query(self::LINK)->length);
        $this->assertSame(1, $dom->query('//section[@data-agent-present]')->length);
        $this->assertSame(0, $dom->query('//a[@data-assistant-fab]')->length);
    }

    /** @return array<string, array{string}> */
    public static function accountsTheAgentDoesNotAccept(): array
    {
        return [
            'no UPN yet' => ['no-upn'],
            'guest' => ['guest'],
            'company with no mapping' => ['unmapped'],
        ];
    }

    #[DataProvider('accountsTheAgentDoesNotAccept')]
    public function test_a_configured_connection_falls_back_to_the_old_button_for_an_account_it_does_not_accept(string $kind): void
    {
        $this->turnTheAssistantOn();
        $account = match ($kind) {
            'no-upn' => User::factory()->microsoft($this->segurex)->create(['email' => 'n@segurex.com', 'upn' => null]),
            'guest' => User::factory()->microsoft($this->segurex)->create(['email' => 'g@example.test', 'is_guest' => true]),
            'unmapped' => User::factory()->microsoft(Company::factory()->create(['slug' => 'tercera', 'domains' => ['tercera.com']]))->create(['email' => 't@tercera.com', 'upn' => 't@tercera.com']),
        };

        $dom = $this->page('/', $account);

        $this->assertSame(1, $dom->query('//a[@data-assistant-fab]')->length);
        $this->assertSame(0, $dom->query(self::LINK)->length);
        $this->assertSame(0, $dom->query('//*[@data-agent-present]')->length);
    }

    private function turnTheAssistantOn(): void
    {
        config(['assistant.enabled' => true, 'services.anthropic.key' => 'sk-ant-test']);
        DB::table('portal_settings')->insert(['key' => AssistantSwitch::SETTING, 'value' => 'true']);
    }

    // --- Headers (app/Http/Middleware/SecurityHeaders.php) ---

    public function test_the_microphone_is_delegated_only_to_the_agent_origin(): void
    {
        $policy = (string) $this->actingAs($this->user)->get('/')->headers->get('Permissions-Policy');

        $this->assertSame('camera=(), microphone=("'.self::ORIGIN.'"), geolocation=()', $policy);
        $this->assertStringNotContainsString('self', $policy);
        $this->assertStringNotContainsString('*', $policy);
    }

    public function test_every_response_delegates_it_while_the_connection_is_on_and_none_otherwise(): void
    {
        $this->get('/no-existe')->assertNotFound()->assertHeader('Permissions-Policy', 'camera=(), microphone=("'.self::ORIGIN.'"), geolocation=()');

        config(['agent.panel_key' => null]);
        $this->get(route('login'))->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_the_content_security_policy_is_untouched(): void
    {
        $csp = (string) $this->actingAs($this->user)->get('/')->headers->get('Content-Security-Policy');

        $this->assertSame("frame-ancestors 'none'", $csp);
        $this->assertStringNotContainsString(self::ORIGIN, $csp);
    }
}
