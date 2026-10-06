<?php

namespace Tests\Feature\Pwa;

use App\Models\User;
use App\Pwa\PortalUserKey;
use App\Settings\PortalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The service worker keeps one saved home page and drops it as soon as a
 * page says someone else (or nobody) is signed in: every web response
 * names its user with an opaque key.
 */
class CachePrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_name_the_signed_in_user_with_an_opaque_key(): void
    {
        $ana = User::factory()->create();
        $luis = User::factory()->create();

        $key = $this->actingAs($ana)->get('/')->assertOk()->headers->get(PortalUserKey::HEADER);

        $this->assertSame(PortalUserKey::for($ana), $key);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{20}$/', $key);
        $this->assertNotSame(PortalUserKey::for($luis), $key);
    }

    public function test_guest_pages_say_nobody_is_signed_in(): void
    {
        $this->get('/login')->assertOk()->assertHeader(PortalUserKey::HEADER, PortalUserKey::GUEST);
    }

    public function test_api_responses_carry_no_user_key(): void
    {
        $this->getJson('/api/v1/apps')->assertHeaderMissing(PortalUserKey::HEADER);
    }

    public function test_logout_clears_the_browser_cache_and_storage_but_keeps_cookies(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/logout')
            ->assertRedirect(route('login'))
            ->assertHeader('Clear-Site-Data', '"cache", "storage"')
            ->assertHeader(PortalUserKey::HEADER, PortalUserKey::GUEST);
    }

    public function test_logout_through_microsoft_clears_them_too(): void
    {
        app(PortalSettings::class)->update(['microsoft_logout' => true], null);

        $response = $this->actingAs(User::factory()->create())->post('/logout');

        $this->assertStringStartsWith('https://login.microsoftonline.com/', (string) $response->headers->get('Location'));
        $response->assertHeader('Clear-Site-Data', '"cache", "storage"');
    }

    public function test_the_home_page_marks_itself_as_fresh_and_has_a_hidden_offline_notice(): void
    {
        $this->actingAs(User::factory()->create())->get('/')
            ->assertOk()
            ->assertSee('data-pwa-source="network"', false)
            ->assertSee('<p role="status" data-offline-notice hidden', false)
            ->assertSee('Sin conexión · mostrando tus apps guardadas')
            ->assertSee('data-logout', false);
    }
}
