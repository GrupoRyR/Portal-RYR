<?php

namespace Tests\Feature\Mcp;

use App\Mcp\ConnectionAccounts;
use App\Models\Company;
use App\Models\McpConnection;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The accounts an MCP call may use: the connection's confirmed accounts
 * that can still connect. The check only removes accounts; the person
 * never adds one.
 */
class ConnectionAccountsTest extends TestCase
{
    use InteractsWithMcp, RefreshDatabase;

    private User $approver;

    private User $linked;

    private McpConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $segurex = Company::factory()->create(['short_name' => 'Segurex']);
        $derivados = Company::factory()->create(['short_name' => 'Derivados']);
        $person = Person::query()->create();

        $this->approver = User::factory()->microsoft($segurex)->create();
        $this->linked = User::factory()->microsoft($derivados)->create();
        $this->approver->forceFill(['person_id' => $person->id])->save();
        $this->linked->forceFill(['person_id' => $person->id])->save();

        $this->connection = McpConnection::query()->create([
            'oauth_client_id' => $this->registeredClient(),
            'user_id' => $this->approver->id,
        ]);
        // As C2b will confirm it: the linked account signed in with Microsoft.
        $this->connection->accounts()->create(['user_id' => $this->approver->id, 'confirmed_at' => now()]);
        $this->connection->accounts()->create(['user_id' => $this->linked->id, 'confirmed_at' => now()]);
    }

    private function registeredClient(): string
    {
        $this->enableMcp();

        return $this->registeredClientId();
    }

    /**
     * @return list<int>
     */
    private function usable(): array
    {
        return app(ConnectionAccounts::class)->for($this->connection->fresh())->pluck('id')->all();
    }

    public function test_confirmed_accounts_that_can_still_connect_are_used(): void
    {
        $this->assertSame([$this->approver->id, $this->linked->id], $this->usable());
        $this->assertTrue(app(ConnectionAccounts::class)->approverMayConnect($this->connection));
    }

    public function test_an_unlinked_account_drops_out(): void
    {
        $this->linked->forceFill(['person_id' => null])->save();

        $this->assertSame([$this->approver->id], $this->usable());
    }

    public function test_a_guest_or_emergency_linked_account_drops_out(): void
    {
        $this->linked->forceFill(['is_guest' => true])->save();
        $this->assertSame([$this->approver->id], $this->usable());

        $this->linked->forceFill(['is_guest' => false, 'is_emergency' => true])->save();
        $this->assertSame([$this->approver->id], $this->usable());
    }

    public function test_an_account_of_an_inactive_company_drops_out(): void
    {
        $this->linked->company->update(['is_active' => false]);

        $this->assertSame([$this->approver->id], $this->usable());
    }

    public function test_the_person_never_adds_an_account_that_was_not_confirmed(): void
    {
        $third = User::factory()->microsoft()->create();
        $third->forceFill(['person_id' => $this->approver->person_id])->save();

        $this->assertNotContains($third->id, $this->usable());
    }

    public function test_an_approver_that_can_no_longer_connect_leaves_nothing(): void
    {
        $this->approver->forceFill(['is_guest' => true])->save();

        $this->assertFalse(app(ConnectionAccounts::class)->approverMayConnect($this->connection->fresh()));
        $this->assertSame([], $this->usable());
    }
}
