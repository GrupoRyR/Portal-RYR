<?php

namespace Tests\Feature\Push;

use App\Models\Company;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Signing out stops phone notices on that browser (odd/tasks/avisos.md R3):
 * the session remembers which devices it subscribed, and logout deletes
 * those of the account, so the next person on a shared phone gets nothing
 * of the previous one. Other devices of the account stay.
 */
class PushLogoutTest extends TestCase
{
    use PushTestKeys;
    use RefreshDatabase;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurePush();
        $this->ana = User::factory()->microsoft(Company::factory()->create())->create();
    }

    private function subscribe(string $endpoint): void
    {
        $this->postJson(route('account.push.store'), [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => self::P256DH, 'auth' => self::AUTH],
        ])->assertNoContent();
    }

    public function test_signing_out_deletes_the_subscription_of_this_browser_only(): void
    {
        $otherPhone = PushSubscription::factory()->for($this->ana)->create();
        $luis = PushSubscription::factory()->create();

        $this->actingAs($this->ana);
        $this->subscribe('https://fcm.googleapis.com/fcm/send/this-browser');

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->assertEqualsCanonicalizing([$otherPhone->id, $luis->id], PushSubscription::query()->pluck('id')->all());
    }

    public function test_a_device_moved_to_another_account_is_not_deleted_by_the_old_session(): void
    {
        $this->actingAs($this->ana);
        $this->subscribe('https://fcm.googleapis.com/fcm/send/shared');

        // Meanwhile Luis subscribed the same browser (it moved to him).
        $luis = User::factory()->microsoft(Company::factory()->create())->create();
        PushSubscription::query()->update(['user_id' => $luis->id]);

        $this->post(route('logout'));

        $this->assertSame($luis->id, PushSubscription::query()->sole()->user_id);
    }

    public function test_unsubscribing_forgets_it_in_the_session_too(): void
    {
        $this->actingAs($this->ana);
        $this->subscribe('https://fcm.googleapis.com/fcm/send/once');
        $this->deleteJson(route('account.push.destroy'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/once'])->assertNoContent();

        $this->assertSame([], session('push.endpoint_hashes', []));
    }

    public function test_signing_out_without_notices_changes_nothing(): void
    {
        $kept = PushSubscription::factory()->for($this->ana)->create();

        $this->actingAs($this->ana)->post(route('logout'))->assertRedirect(route('login'));

        $this->assertSame([$kept->id], PushSubscription::query()->pluck('id')->all());
    }
}
