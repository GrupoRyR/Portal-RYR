<?php

namespace Tests\Feature\Agent;

use App\Agent\Manifest;
use App\Agent\Views\AgentViews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The manifest follows the connection contract (v0.4, §3.5) and the agent's
 * importer rules, carries no data, and is served publicly without a session.
 */
class ManifestTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://portal.example.test';

    /**
     * Hash of the published content for the version below. When the manifest
     * changes, bump Manifest::VERSION and update this pair: the agent keeps
     * the version and hash of the last import.
     */
    private const PINNED_VERSION = '2026-11-12.1';

    private const PINNED_HASH = '54deba2d5594441f76d4094d17b33723117000f8cfb41726fdd13500d9120bed';

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        return json_decode((string) json_encode(Manifest::build(self::URL)), true);
    }

    public function test_the_content_does_not_change_without_a_new_version(): void
    {
        $hash = hash('sha256', (string) json_encode(Manifest::build(self::URL)));

        $this->assertSame(
            [self::PINNED_VERSION, self::PINNED_HASH],
            [Manifest::VERSION, $hash],
            'The manifest changed: bump Manifest::VERSION and update PINNED_VERSION and PINNED_HASH.',
        );
    }

    public function test_header_roles_and_data(): void
    {
        $m = $this->manifest();

        $this->assertLessThanOrEqual(32, strlen($m['version']));
        $this->assertSame(['slug' => 'portal-ryr', 'nombre' => 'Portal RYR', 'empresas' => ['SGX', 'SDC'], 'url' => self::URL], $m['app']);
        $this->assertSame(['empleado', 'gerencia'], $m['roles']);
        $this->assertSame(['empleado', 'gerencia'], $m['roles_por_persona']);
        $this->assertSame(['base' => 'portal', 'usuario_ro' => 'agente_portal_ro'], $m['datos']);
    }

    public function test_per_person_roles_are_the_lowest_ranks(): void
    {
        $m = $this->manifest();

        $this->assertSame(array_slice($m['roles'], 0, count($m['roles_por_persona'])), $m['roles_por_persona']);
    }

    public function test_five_queries_and_no_actions(): void
    {
        $tools = $this->manifest()['herramientas'];

        $this->assertSame(
            ['anuncios_vigentes', 'proximos_eventos', 'mis_pendientes', 'estado_de_ti', 'kpis_gerenciales'],
            array_column($tools, 'nombre'),
        );
        $this->assertSame(['consulta'], array_values(array_unique(array_column($tools, 'tipo'))));
    }

    public function test_every_view_is_used_by_exactly_one_tool(): void
    {
        $views = array_column(array_column($this->manifest()['herramientas'], 'fuente'), 'vista');

        $this->assertEqualsCanonicalizing(AgentViews::names(), $views);
    }

    public function test_every_tool_follows_the_contract_rules(): void
    {
        $m = $this->manifest();

        foreach ($m['herramientas'] as $t) {
            $n = $t['nombre'];
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]{0,63}$/', $n);
            $this->assertNotSame('', trim($t['etiqueta']), "{$n}: label");
            $this->assertGreaterThanOrEqual(20, mb_strlen($t['descripcion']), $n);
            $this->assertLessThanOrEqual(1024, mb_strlen($t['descripcion']), $n);
            $this->assertContains($t['rol_minimo'], $m['roles'], $n);
            $this->assertGreaterThan(0, $t['limite_filas'], $n);
            $this->assertLessThanOrEqual(500, $t['limite_filas'], $n);

            // Source: a v_ view with exactly the columns of the view itself.
            $view = $t['fuente']['vista'];
            $this->assertStringStartsWith('v_', $view);
            $this->assertSame(AgentViews::columns($view), $t['fuente']['columnas'], $n);
            $this->assertContains('empresa', $t['fuente']['columnas'], $n);

            // Every tool declares the company filter (an object, contract 3.5).
            $this->assertArrayHasKey('empresa', $t['filtros_obligatorios'], $n);
            $this->assertSame('empresa', $t['filtros_obligatorios']['empresa'], $n);
            foreach ($t['filtros_obligatorios'] as $column) {
                $this->assertContains($column, $t['fuente']['columnas'], $n);
            }

            // Parameters: equality against a view column that is not a filter column (agent rule N2).
            $schema = $t['parametros'];
            $this->assertSame('object', $schema['type']);
            $this->assertFalse($schema['additionalProperties'], $n);
            $this->assertIsArray($schema['required'], $n);
            foreach ($schema['properties'] as $name => $property) {
                $this->assertContains($name, $t['fuente']['columnas'], "{$n}: {$name} is not a column");
                $this->assertNotContains($name, $t['filtros_obligatorios'], "{$n}: {$name} is a filter column");
                $this->assertContains($property['type'], ['string', 'integer', 'number', 'boolean'], $n);
            }
            $this->assertSame([], $this->forbiddenKeywords($schema), $n);

            // Order: up to three, columns of the view.
            $this->assertNotEmpty($t['orden'], "{$n}: no order");
            $this->assertLessThanOrEqual(3, count($t['orden']), $n);
            foreach ($t['orden'] as $o) {
                $this->assertContains($o['columna'], $t['fuente']['columnas'], $n);
                $this->assertContains($o['direccion'], ['asc', 'desc'], $n);
            }
        }
    }

    public function test_only_my_pending_items_filters_by_person_and_only_by_the_owner_column(): void
    {
        $byName = array_column($this->manifest()['herramientas'], null, 'nombre');

        $this->assertSame(['empresa' => 'empresa', 'usuario' => 'usuario_correo'], $byName['mis_pendientes']['filtros_obligatorios']);

        foreach (['anuncios_vigentes', 'proximos_eventos', 'estado_de_ti', 'kpis_gerenciales'] as $name) {
            $this->assertSame(['empresa' => 'empresa'], $byName[$name]['filtros_obligatorios'], $name);
            $this->assertNotContains('usuario_correo', $byName[$name]['fuente']['columnas'], $name);
        }
    }

    public function test_only_the_management_figures_need_the_higher_role(): void
    {
        $roles = array_column($this->manifest()['herramientas'], 'rol_minimo', 'nombre');

        $this->assertSame('gerencia', $roles['kpis_gerenciales']);
        unset($roles['kpis_gerenciales']);
        $this->assertSame(['empleado'], array_values(array_unique($roles)));
    }

    public function test_sorted_lists_declare_the_order_their_name_promises(): void
    {
        $byName = array_column($this->manifest()['herramientas'], null, 'nombre');

        $this->assertSame('proxima_fecha', $byName['proximos_eventos']['orden'][0]['columna']);
        $this->assertSame('asc', $byName['proximos_eventos']['orden'][0]['direccion']);
        $this->assertSame('fijado', $byName['anuncios_vigentes']['orden'][0]['columna']);
        $this->assertSame('desc', $byName['anuncios_vigentes']['orden'][0]['direccion']);
    }

    public function test_the_manifest_carries_no_secrets_or_data(): void
    {
        $json = (string) json_encode(Manifest::build(self::URL));

        foreach (['password', 'llave', 'token', 'secret', 'APPDB', '@'] as $word) {
            $this->assertStringNotContainsString($word, $json);
        }
    }

    /** @return list<string> */
    private function forbiddenKeywords(mixed $node): array
    {
        $found = [];
        if (is_array($node)) {
            foreach ($node as $key => $value) {
                if (in_array($key, ['oneOf', 'not', 'if', 'patternProperties', 'prefixItems'], true)) {
                    $found[] = (string) $key;
                }
                $found = array_merge($found, $this->forbiddenKeywords($value));
            }
        }

        return $found;
    }

    // --- Public route ---

    public function test_the_public_route_serves_the_manifest_without_a_session_or_cookies(): void
    {
        config(['agent.app_url' => self::URL]);

        $response = $this->get('/.well-known/agente-ryr.json');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $this->assertSame($this->manifest(), json_decode((string) $response->getContent(), true));
        // With no parameters «properties» must be an object, not a list.
        $this->assertStringContainsString('"properties":{}', (string) $response->getContent());
        $this->assertStringNotContainsString('"properties":[]', (string) $response->getContent());
        $this->assertSame([], $response->headers->getCookies());
        $response->assertHeaderMissing('Set-Cookie');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
    }

    public function test_without_the_agent_app_url_it_falls_back_to_app_url(): void
    {
        config(['agent.app_url' => '', 'app.url' => 'https://portal.example.test/']);

        $this->get('/.well-known/agente-ryr.json')->assertOk()->assertJsonPath('app.url', 'https://portal.example.test');
    }

    public function test_with_an_origin_that_is_not_https_it_answers_503_and_publishes_nothing(): void
    {
        config(['agent.app_url' => '', 'app.url' => 'http://localhost']);

        $this->get('/.well-known/agente-ryr.json')->assertStatus(503)->assertJsonMissingPath('herramientas');
    }

    public function test_it_answers_only_to_get(): void
    {
        config(['agent.app_url' => self::URL]);

        $this->post('/.well-known/agente-ryr.json')->assertStatus(405);
    }

    public function test_it_needs_no_agent_key_so_it_can_be_imported_before_the_button_is_switched_on(): void
    {
        config(['agent.app_url' => self::URL, 'agent.panel_key' => null, 'agent.origin' => null]);

        $this->get('/.well-known/agente-ryr.json')->assertOk();
    }
}
