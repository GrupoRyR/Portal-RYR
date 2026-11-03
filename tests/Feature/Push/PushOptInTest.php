<?php

namespace Tests\Feature\Push;

use App\Models\Company;
use App\Models\User;
use App\Push\PushSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Avisos en el celular» on /cuenta: a small control without Alpine that
 * asks for permission, subscribes with the VAPID public key and posts the
 * subscription. Only while the three VAPID settings are present.
 */
class PushOptInTest extends TestCase
{
    use PushTestKeys;
    use RefreshDatabase;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ana = User::factory()->microsoft(Company::factory()->create())->create();
    }

    public function test_the_account_page_offers_phone_notices_with_the_public_key(): void
    {
        $this->configurePush();

        $html = $this->actingAs($this->ana)->get(route('account.show'))->assertOk()->getContent();

        $this->assertStringContainsString('Avisos en el celular', $html);
        $this->assertStringContainsString('data-push-optin', $html);
        $this->assertStringContainsString('data-vapid-key="'.self::VAPID_PUBLIC.'"', $html);
        $this->assertStringContainsString('data-subscribe-url="'.route('account.push.store').'"', $html);
        $this->assertStringContainsString('data-csrf="', $html);
        // The private key never reaches a page.
        $this->assertStringNotContainsString(self::VAPID_PRIVATE, $html);
    }

    public function test_it_explains_that_iphone_needs_the_installed_app(): void
    {
        $this->configurePush();

        $html = $this->actingAs($this->ana)->get(route('account.show'))->getContent();

        $this->assertStringContainsString('iPhone', $html);
        $this->assertStringContainsString('iOS 16.4', $html);
        $this->assertStringContainsString('pantalla de inicio', $html);
    }

    public function test_the_control_is_plain_javascript_that_subscribes_and_posts(): void
    {
        $this->configurePush();

        $html = $this->actingAs($this->ana)->get(route('account.show'))->getContent();

        $this->assertStringContainsString('Notification.requestPermission()', $html);
        $this->assertStringContainsString('pushManager', $html);
        $this->assertStringContainsString('manager.subscribe(options)', $html);
        $this->assertStringContainsString('userVisibleOnly: true', $html);
        $this->assertStringContainsString("'X-CSRF-TOKEN'", $html);
        $this->assertStringNotContainsString('x-data', $html);
        $this->assertStringNotContainsString('wire:', $html);
    }

    public function test_without_the_vapid_settings_the_page_has_no_control(): void
    {
        $this->assertFalse(app(PushSettings::class)->enabled());

        $html = $this->actingAs($this->ana)->get(route('account.show'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-push-optin', $html);
        $this->assertStringNotContainsString('Avisos en el celular', $html);
    }

    public function test_the_settings_need_all_three_values_and_a_valid_subject(): void
    {
        $this->configurePush();
        $this->assertTrue(app(PushSettings::class)->enabled());
        $this->assertSame(self::VAPID_PUBLIC, app(PushSettings::class)->publicKey());

        config(['push.vapid.subject' => 'https://apps.segurexlatam.net']);
        $this->assertTrue(app(PushSettings::class)->enabled());

        foreach (['', '   ', 'soporte@segurexlatam.net', 'http://apps.segurexlatam.net'] as $subject) {
            config(['push.vapid.subject' => $subject]);
            $this->assertFalse(app(PushSettings::class)->enabled(), "subject «{$subject}»");
        }

        $this->configurePush();
        config(['push.vapid.private_key' => '']);
        $this->assertFalse(app(PushSettings::class)->enabled());
        $this->assertNull(app(PushSettings::class)->publicKey());
    }
}
