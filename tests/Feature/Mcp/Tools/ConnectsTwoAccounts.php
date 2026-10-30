<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\Company;
use App\Models\McpConnection;
use App\Models\Person;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Mcp\InteractsWithMcp;

/**
 * A Claude connection with two linked accounts of one person, one in
 * Segurex (the approving one) and one in Derivados, plus a third company
 * («Otra») that is not in the connection: its data must never come back.
 * The approving account connects through the real OAuth flow; the linked
 * one is stored as C2b stores it after its own Microsoft sign-in.
 *
 * The clock stands on Wednesday 4 November 2026, 09:00 in Bogotá.
 */
trait ConnectsTwoAccounts
{
    use InteractsWithMcp;

    protected Company $segurex;

    protected Company $derivados;

    protected Company $other;

    protected User $approver;

    protected User $linked;

    protected string $token;

    protected function connectTwoAccounts(): void
    {
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00', 'America/Bogota'));
        $this->enableMcp();

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam S.A.S.', 'short_name' => 'Segurex']);
        $this->derivados = Company::factory()->create(['name' => 'Soluciones y Derivados', 'short_name' => 'Derivados']);
        $this->other = Company::factory()->create(['name' => 'Otra Empresa S.A.S.', 'short_name' => 'Otra']);

        $this->approver = User::factory()->microsoft($this->segurex)->create([
            'name' => 'Jhon Castro',
            'email' => 'jhon.castro@segurex.com',
        ]);
        $this->linked = User::factory()->microsoft($this->derivados)->create([
            'name' => 'Jhon Castro',
            'email' => 'derivados.info@derivados.com',
        ]);

        $person = Person::query()->create();
        $this->approver->forceFill(['person_id' => $person->id])->save();
        $this->linked->forceFill(['person_id' => $person->id])->save();

        $this->token = $this->connect($this->approver)['access_token'];

        // As C2b stores it: the linked account signed in with Microsoft
        // while connecting.
        McpConnection::query()->sole()->accounts()->create(['user_id' => $this->linked->id, 'confirmed_at' => now()]);
    }

    /**
     * A user of the company that is not in the connection.
     */
    protected function outsider(): User
    {
        return User::factory()->microsoft($this->other)->create();
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function callTool(string $name, array $arguments = []): TestResponse
    {
        return $this->mcp($this->token, 'tools/call', ['name' => $name, 'arguments' => (object) $arguments])->assertOk();
    }

    /**
     * The structured result of a successful call.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function toolResult(string $name, array $arguments = []): array
    {
        $call = $this->callTool($name, $arguments);

        $this->assertNotTrue($call->json('result.isError'), (string) $call->json('result.content.0.text'));

        return (array) $call->json('result.structuredContent');
    }

    /**
     * The message of a call that failed as a tool error.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function toolError(string $name, array $arguments = []): string
    {
        $call = $this->callTool($name, $arguments);

        $this->assertTrue($call->json('result.isError'), 'The call did not fail: '.$call->getContent());

        return (string) $call->json('result.content.0.text');
    }

    /**
     * The linked account leaves the person: it drops out of the connection
     * on the next call (App\Mcp\ConnectionAccounts).
     */
    protected function unlinkTheLinkedAccount(): void
    {
        $this->linked->forceFill(['person_id' => null])->save();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function linkedAccountThatCanNoLongerConnect(): array
    {
        return [
            'unlinked' => ['unlinked'],
            'made a guest' => ['guest'],
            'made an emergency account' => ['emergency'],
            'its company deactivated' => ['inactive company'],
        ];
    }

    protected function makeLinkedAccountUnusable(string $case): void
    {
        match ($case) {
            'unlinked' => $this->unlinkTheLinkedAccount(),
            'guest' => $this->linked->forceFill(['is_guest' => true])->save(),
            'emergency' => $this->linked->forceFill(['is_emergency' => true])->save(),
            'inactive company' => $this->derivados->forceFill(['is_active' => false])->save(),
        };
    }

    /**
     * None of the keys appear anywhere in the result, at any depth.
     *
     * @param  array<string, mixed>  $result
     * @param  list<string>  $keys
     */
    protected function assertNoKeys(array $result, array $keys): void
    {
        $found = [];
        $walk = function (array $node) use (&$walk, &$found, $keys): void {
            foreach ($node as $key => $value) {
                if (is_string($key) && in_array($key, $keys, true)) {
                    $found[] = $key;
                }
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($result);

        $this->assertSame([], $found, 'Forbidden keys in the result.');
    }
}
