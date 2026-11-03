<?php

namespace Tests\Feature\Push;

use App\Models\Company;
use App\Models\PushSubscription;
use App\Models\User;
use App\Push\PushSubscriptions;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * «Avisos en el celular» on /cuenta: POST /cuenta/avisos stores the
 * browser's push subscription for the signed-in account, DELETE removes
 * it. Off (404) without the three VAPID settings.
 */
class PushSubscriptionTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function subscription(string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123', array $overrides = []): array
    {
        return array_replace_recursive([
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => self::P256DH, 'auth' => self::AUTH],
            'contentEncoding' => 'aes128gcm',
        ], $overrides);
    }

    public function test_a_signed_in_account_subscribes_its_device(): void
    {
        $this->actingAs($this->ana)
            ->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)')
            ->postJson(route('account.push.store'), $this->subscription())
            ->assertNoContent();

        $row = PushSubscription::query()->sole();
        $this->assertSame($this->ana->id, $row->user_id);
        $this->assertSame('https://fcm.googleapis.com/fcm/send/abc123', $row->endpoint);
        $this->assertSame(hash('sha256', 'https://fcm.googleapis.com/fcm/send/abc123'), $row->endpoint_hash);
        $this->assertSame(64, strlen($row->endpoint_hash));
        $this->assertSame(self::P256DH, $row->public_key);
        $this->assertSame(self::AUTH, $row->auth_token);
        $this->assertSame('aes128gcm', $row->content_encoding);
        $this->assertStringContainsString('iPhone', (string) $row->user_agent);
    }

    public function test_the_same_endpoint_again_is_updated_and_belongs_to_the_account_that_sent_it(): void
    {
        $luis = User::factory()->microsoft(Company::factory()->create())->create();

        $this->actingAs($this->ana)->postJson(route('account.push.store'), $this->subscription())->assertNoContent();
        $this->actingAs($luis)->postJson(route('account.push.store'), $this->subscription(overrides: ['keys' => ['auth' => 'bmV3LWF1dGgtc2VjcmV0']]))->assertNoContent();

        $row = PushSubscription::query()->sole();
        $this->assertSame($luis->id, $row->user_id);
        $this->assertSame('bmV3LWF1dGgtc2VjcmV0', $row->auth_token);
    }

    public function test_the_endpoint_must_be_https_at_a_known_push_service(): void
    {
        $this->actingAs($this->ana);

        foreach ([
            'http://fcm.googleapis.com/fcm/send/abc',
            'https://evil.example.com/fcm/send/abc',
            'https://fcm.googleapis.com.evil.example.com/x',
            'https://127.0.0.1/x',
            'https://user:pass@fcm.googleapis.com/x',
            'https://fcm.googleapis.com:8443/x',
            'not a url',
        ] as $endpoint) {
            $this->postJson(route('account.push.store'), $this->subscription($endpoint))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('endpoint');
        }

        foreach ([
            'https://fcm.googleapis.com/fcm/send/a',
            'https://updates.push.services.mozilla.com/wpush/v2/b',
            'https://web.push.apple.com/QOx-c',
            'https://wns2-par02p.notify.windows.com/w/?token=d',
        ] as $endpoint) {
            $this->postJson(route('account.push.store'), $this->subscription($endpoint))->assertNoContent();
        }

        $this->assertSame(4, PushSubscription::query()->count());
    }

    public function test_the_keys_are_required_and_base64url(): void
    {
        $this->actingAs($this->ana)
            ->postJson(route('account.push.store'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['keys.p256dh', 'keys.auth']);

        $this->postJson(route('account.push.store'), $this->subscription(overrides: ['keys' => ['p256dh' => 'no <html> here', 'auth' => str_repeat('a', 300)]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['keys.p256dh', 'keys.auth']);

        $this->postJson(route('account.push.store'), $this->subscription(overrides: ['contentEncoding' => 'gzip']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contentEncoding');

        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_an_account_keeps_only_its_newest_subscriptions(): void
    {
        $this->actingAs($this->ana);

        for ($i = 1; $i <= PushSubscriptions::MAX_PER_USER + 2; $i++) {
            $this->travel(1)->minutes();
            $this->postJson(route('account.push.store'), $this->subscription("https://fcm.googleapis.com/fcm/send/device-{$i}"))->assertNoContent();
        }

        $kept = PushSubscription::query()->where('user_id', $this->ana->id)->pluck('endpoint');
        $this->assertCount(PushSubscriptions::MAX_PER_USER, $kept);
        $this->assertNotContains('https://fcm.googleapis.com/fcm/send/device-1', $kept);
        $this->assertNotContains('https://fcm.googleapis.com/fcm/send/device-2', $kept);
        $this->assertContains('https://fcm.googleapis.com/fcm/send/device-'.(PushSubscriptions::MAX_PER_USER + 2), $kept);
    }

    public function test_unsubscribing_removes_only_the_accounts_own_subscription(): void
    {
        $luis = User::factory()->microsoft(Company::factory()->create())->create();
        $this->actingAs($this->ana)->postJson(route('account.push.store'), $this->subscription('https://fcm.googleapis.com/fcm/send/ana'))->assertNoContent();
        $this->actingAs($luis)->postJson(route('account.push.store'), $this->subscription('https://fcm.googleapis.com/fcm/send/luis'))->assertNoContent();

        // Luis cannot remove Ana's device, even knowing its endpoint.
        $this->actingAs($luis)->deleteJson(route('account.push.destroy'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/ana'])->assertNoContent();
        $this->assertSame(2, PushSubscription::query()->count());

        $this->actingAs($this->ana)->deleteJson(route('account.push.destroy'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/ana'])->assertNoContent();
        $this->assertSame(['https://fcm.googleapis.com/fcm/send/luis'], PushSubscription::query()->pluck('endpoint')->all());

        $this->deleteJson(route('account.push.destroy'), [])->assertUnprocessable()->assertJsonValidationErrors('endpoint');
    }

    public function test_deleting_the_account_deletes_its_subscriptions(): void
    {
        $this->actingAs($this->ana)->postJson(route('account.push.store'), $this->subscription())->assertNoContent();

        $this->ana->delete();

        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_only_signed_in_accounts_with_a_company_may_subscribe(): void
    {
        $this->postJson(route('account.push.store'), $this->subscription())->assertUnauthorized();
        $this->deleteJson(route('account.push.destroy'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123'])->assertUnauthorized();

        // The emergency account sees no announcements: nothing to send it.
        $this->actingAs(User::factory()->emergency()->create())
            ->postJson(route('account.push.store'), $this->subscription())
            ->assertNotFound();

        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_without_the_vapid_settings_the_endpoints_do_not_exist(): void
    {
        foreach (['push.vapid.public_key', 'push.vapid.private_key', 'push.vapid.subject'] as $missing) {
            $this->configurePush();
            config([$missing => null]);

            $this->actingAs($this->ana)->postJson(route('account.push.store'), $this->subscription())->assertNotFound();
            $this->actingAs($this->ana)->deleteJson(route('account.push.destroy'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123'])->assertNotFound();
        }

        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_the_endpoints_need_the_csrf_token_and_are_rate_limited(): void
    {
        foreach (['account.push.store', 'account.push.destroy'] as $name) {
            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();
            $this->assertContains('web', $middleware);
            $this->assertContains('auth', $middleware);
            $this->assertNotEmpty(array_filter($middleware, fn (string $m) => str_starts_with($m, 'throttle:')));
        }

        // The testing kernel skips CSRF; check it as a real request would.
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });

        $this->actingAs($this->ana)->postJson(route('account.push.store'), $this->subscription())->assertStatus(419);
        $this->assertSame(0, PushSubscription::query()->count());
    }
}
