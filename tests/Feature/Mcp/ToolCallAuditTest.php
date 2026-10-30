<?php

namespace Tests\Feature\Mcp;

use App\Audit\SecurityLog;
use App\Mcp\Connections;
use App\Models\McpConnection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * One audit line per tool call (`mcp.tool_called`): the tool, the
 * connection and the approving account, with the time of the entry.
 * Never the arguments nor what the tool returned.
 */
class ToolCallAuditTest extends TestCase
{
    use InteractsWithMcp, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMcp();
    }

    /**
     * @return Collection<int, Activity>
     */
    private function toolCalls()
    {
        return Activity::query()->where('event', Connections::TOOL_CALLED)->orderBy('id')->get();
    }

    public function test_a_tool_call_writes_one_line_with_the_tool_the_connection_and_the_account(): void
    {
        $user = $this->eligibleUser();
        $tokens = $this->connect($user);

        $this->mcp($tokens['access_token'], 'tools/call', ['name' => 'quien_soy', 'arguments' => []])->assertOk();

        $calls = $this->toolCalls();
        $this->assertCount(1, $calls);
        $this->assertSame(SecurityLog::LOG, $calls[0]->log_name);
        $this->assertTrue($calls[0]->causer->is($user));
        $this->assertSame([
            'tool' => 'quien_soy',
            'connection_id' => McpConnection::query()->sole()->getKey(),
            'error' => false,
        ], $calls[0]->properties->all());
        $this->assertNotNull($calls[0]->created_at);
    }

    public function test_the_arguments_and_the_result_are_never_stored(): void
    {
        $user = $this->eligibleUser();
        $tokens = $this->connect($user);

        $this->mcp($tokens['access_token'], 'tools/call', [
            'name' => 'buscar_personas',
            'arguments' => ['texto' => 'secreto-de-busqueda'],
        ])->assertOk();

        $call = $this->toolCalls()->sole();
        $stored = json_encode($call->getAttributes(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('secreto-de-busqueda', $stored);
        $this->assertStringNotContainsString('arguments', $stored);
        $this->assertStringNotContainsString($user->email, (string) json_encode($call->properties));
        $this->assertSame(['tool', 'connection_id', 'error'], array_keys($call->properties->all()));
    }

    public function test_each_call_writes_its_own_line(): void
    {
        $tokens = $this->connect($this->eligibleUser());

        $this->mcp($tokens['access_token'], 'tools/call', ['name' => 'quien_soy', 'arguments' => []])->assertOk();
        $this->mcp($tokens['access_token'], 'tools/call', ['name' => 'mis_apps', 'arguments' => []])->assertOk();

        $this->assertSame(['quien_soy', 'mis_apps'], $this->toolCalls()->map(fn (Activity $call) => $call->properties['tool'])->all());
    }

    public function test_listing_tools_writes_nothing(): void
    {
        $tokens = $this->connect($this->eligibleUser());

        $this->mcp($tokens['access_token'], 'tools/list')->assertOk();
        $this->mcp($tokens['access_token'], 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'x', 'version' => '1']])->assertOk();

        $this->assertCount(0, $this->toolCalls());
    }

    public function test_a_call_that_ends_in_an_error_is_flagged(): void
    {
        $tokens = $this->connect($this->eligibleUser());

        $this->mcp($tokens['access_token'], 'tools/call', ['name' => 'buscar_personas', 'arguments' => ['texto' => 'a']])
            ->assertOk()
            ->assertJsonPath('result.isError', true);

        $call = $this->toolCalls()->sole();
        $this->assertSame('buscar_personas', $call->properties['tool']);
        $this->assertTrue($call->properties['error']);
        $this->assertArrayNotHasKey('refused', $call->properties->all());
    }

    public function test_an_unknown_tool_is_audited_as_a_refused_call_with_its_name_cut_to_64_characters(): void
    {
        $tokens = $this->connect($this->eligibleUser());
        $name = 'borrar_todo_'.str_repeat('x', 100);

        $this->mcp($tokens['access_token'], 'tools/call', ['name' => $name, 'arguments' => ['secreto' => 'no-guardar']])
            ->assertJsonPath('error.code', -32602);

        $call = $this->toolCalls()->sole();
        $this->assertSame([
            'tool' => mb_substr($name, 0, 64),
            'connection_id' => McpConnection::query()->sole()->getKey(),
            'error' => true,
            'refused' => true,
        ], $call->properties->all());
        $this->assertStringNotContainsString('no-guardar', (string) json_encode($call->getAttributes()));
    }

    public function test_a_call_without_a_tool_name_is_audited_as_refused(): void
    {
        $tokens = $this->connect($this->eligibleUser());

        $this->mcp($tokens['access_token'], 'tools/call', ['arguments' => []])->assertJsonPath('error.code', -32602);

        $call = $this->toolCalls()->sole();
        $this->assertNull($call->properties['tool']);
        $this->assertTrue($call->properties['refused']);
    }

    public function test_an_unauthenticated_call_writes_nothing(): void
    {
        $this->mcp('not-a-token', 'tools/call', ['name' => 'quien_soy', 'arguments' => []])->assertUnauthorized();

        $this->assertCount(0, $this->toolCalls());
    }

    public function test_the_line_costs_one_insert_and_no_other_query_on_the_log(): void
    {
        $tokens = $this->connect($this->eligibleUser());
        $this->mcp($tokens['access_token'], 'tools/list')->assertOk();

        $logQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$logQueries) {
            if (str_contains($query->sql, 'activity_log')) {
                $logQueries[] = $query->sql;
            }
        });

        $this->mcp($tokens['access_token'], 'tools/call', ['name' => 'quien_soy', 'arguments' => []])->assertOk();

        $this->assertCount(1, $logQueries);
        $this->assertStringStartsWith('insert into', strtolower($logQueries[0]));
    }
}
