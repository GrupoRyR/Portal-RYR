<?php

namespace Tests\Feature\Users;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserGroupsTest extends TestCase
{
    use RefreshDatabase;

    private const ADMINS = 'aaaaaaaa-0000-0000-0000-000000000001';

    private const SALES = 'aaaaaaaa-0000-0000-0000-000000000002';

    private const PORTFOLIO = 'aaaaaaaa-0000-0000-0000-000000000003';

    public function test_sync_groups_stores_the_given_groups(): void
    {
        $user = User::factory()->create();

        $user->syncGroups([
            ['id' => self::ADMINS, 'display_name' => 'APP-PortalAdmin'],
            ['id' => self::SALES, 'display_name' => 'APP-TomaPedidos'],
        ]);

        $this->assertEqualsCanonicalizing(
            ['APP-PortalAdmin', 'APP-TomaPedidos'],
            $user->groups()->pluck('display_name')->all(),
        );
    }

    public function test_sync_groups_replaces_the_previous_set(): void
    {
        $user = User::factory()->create();
        $user->syncGroups([
            ['id' => self::ADMINS, 'display_name' => 'APP-PortalAdmin'],
            ['id' => self::SALES, 'display_name' => 'APP-TomaPedidos'],
        ]);

        $user->syncGroups([
            ['id' => self::SALES, 'display_name' => 'APP-Ventas'],
            ['id' => self::PORTFOLIO, 'display_name' => 'APP-Portafolio'],
        ]);

        $this->assertEqualsCanonicalizing(
            [self::SALES => 'APP-Ventas', self::PORTFOLIO => 'APP-Portafolio'],
            $user->groups()->pluck('display_name', 'group_id')->all(),
        );
    }

    public function test_sync_groups_with_an_empty_list_removes_every_group(): void
    {
        $user = User::factory()->create();
        $user->syncGroups([['id' => self::ADMINS, 'display_name' => 'APP-PortalAdmin']]);

        $user->syncGroups([]);

        $this->assertSame(0, $user->groups()->count());
    }

    public function test_sync_groups_ignores_duplicates_and_items_without_id(): void
    {
        $user = User::factory()->create();

        $user->syncGroups([
            ['id' => strtoupper(self::ADMINS), 'display_name' => 'APP-PortalAdmin'],
            ['id' => self::ADMINS, 'display_name' => 'APP-PortalAdmin'],
            ['id' => '', 'display_name' => 'Sin id'],
            ['display_name' => 'Sin id'],
        ]);

        $this->assertSame([self::ADMINS], $user->groups()->pluck('group_id')->all());
    }

    public function test_sync_groups_does_not_touch_other_users(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $other->syncGroups([['id' => self::ADMINS, 'display_name' => 'APP-PortalAdmin']]);

        $user->syncGroups([]);

        $this->assertSame(1, $other->groups()->count());
    }

    public function test_sync_groups_runs_in_a_single_transaction(): void
    {
        $user = User::factory()->create();
        $transactions = 0;
        DB::connection()->beforeStartingTransaction(function () use (&$transactions) {
            $transactions++;
        });

        $user->syncGroups([
            ['id' => self::ADMINS, 'display_name' => 'APP-PortalAdmin'],
            ['id' => self::SALES, 'display_name' => 'APP-TomaPedidos'],
        ]);

        $this->assertSame(1, $transactions);
    }

    /**
     * Privileges never come from a group name (see App\Auth\PortalAdminGroup).
     */
    public function test_there_is_no_name_based_membership_check(): void
    {
        $this->assertFalse(method_exists(User::class, 'isInGroup'));
    }

    public function test_the_security_flag_is_stored_and_updated(): void
    {
        $user = User::factory()->create();

        $user->syncGroups([['id' => self::ADMINS, 'display_name' => 'APP-PortalAdmin', 'security_group' => true]]);
        $this->assertTrue($user->groups()->sole()->security_group);

        $user->syncGroups([['id' => self::ADMINS, 'display_name' => 'APP-PortalAdmin', 'security_group' => false]]);
        $this->assertFalse($user->groups()->sole()->security_group);

        $user->syncGroups([['id' => self::ADMINS, 'display_name' => 'APP-PortalAdmin']]);
        $this->assertNull($user->groups()->sole()->security_group);
    }

    public function test_a_group_cannot_repeat_for_the_same_user(): void
    {
        $user = User::factory()->create();
        $user->groups()->create(['group_id' => self::ADMINS, 'display_name' => 'APP-PortalAdmin']);

        $this->expectException(QueryException::class);

        $user->groups()->create(['group_id' => self::ADMINS, 'display_name' => 'APP-PortalAdmin']);
    }

    public function test_deleting_a_user_deletes_its_groups(): void
    {
        $user = User::factory()->create();
        $user->syncGroups([['id' => self::ADMINS, 'display_name' => 'APP-PortalAdmin']]);

        $user->delete();

        $this->assertDatabaseCount('user_groups', 0);
    }
}
