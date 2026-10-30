<?php

namespace Tests\Feature\Mcp;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The connector is off until PORTAL_MCP_ENABLED says otherwise: every
 * route of it answers 404 and nothing is registered.
 */
class McpSwitchTest extends TestCase
{
    use InteractsWithMcp, RefreshDatabase;

    public function test_the_connector_is_off_by_default(): void
    {
        $this->assertFalse(config('portal.mcp.enabled'));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function switchValues(): array
    {
        return [
            'off' => ['off', false],
            'no' => ['no', false],
            'false' => ['false', false],
            '0' => ['0', false],
            'empty' => ['', false],
            'anything else' => ['maybe', false],
            'true' => ['true', true],
            '1' => ['1', true],
            'on' => ['on', true],
        ];
    }

    #[DataProvider('switchValues')]
    public function test_the_environment_value_is_read_as_a_strict_boolean(string $value, bool $enabled): void
    {
        $_SERVER['PORTAL_MCP_ENABLED'] = $_ENV['PORTAL_MCP_ENABLED'] = $value;

        try {
            $config = require config_path('portal.php');
        } finally {
            unset($_SERVER['PORTAL_MCP_ENABLED'], $_ENV['PORTAL_MCP_ENABLED']);
        }

        $this->assertSame($enabled, $config['mcp']['enabled']);
    }

    public function test_every_connector_route_answers_404_while_it_is_off(): void
    {
        $this->get('/.well-known/oauth-protected-resource/mcp/portal')->assertNotFound();
        $this->get('/.well-known/oauth-protected-resource')->assertNotFound();
        $this->get('/.well-known/oauth-authorization-server')->assertNotFound();
        $this->get('/oauth/authorize?client_id=x')->assertNotFound();
        $this->post('/oauth/token', ['grant_type' => 'authorization_code'])->assertNotFound();
        $this->postJson('/mcp/portal', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])->assertNotFound();
        $this->get('/mcp/portal')->assertNotFound();
    }

    public function test_no_client_is_registered_while_it_is_off(): void
    {
        $this->registerClient()->assertNotFound();

        $this->assertSame(0, DB::table('oauth_clients')->count());
    }

    public function test_a_signed_in_user_gets_404_too(): void
    {
        $this->actingAs($this->eligibleUser())
            ->get('/oauth/authorize?client_id=x')
            ->assertNotFound();
    }

    public function test_turning_it_on_opens_the_routes(): void
    {
        $this->enableMcp();

        $this->get('/.well-known/oauth-protected-resource/mcp/portal')->assertOk();
        $this->registerClient()->assertCreated();
    }
}
