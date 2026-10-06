<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Mockery;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;
use Tests\TestCase;

class SessionRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_the_login_page_offers_microsoft_sign_in(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Entrar con Microsoft')
            ->assertSee(route('auth.microsoft.redirect'), false);
    }

    public function test_the_login_page_shows_the_flashed_error(): void
    {
        $provider = Mockery::mock(MicrosoftProvider::class);
        $provider->shouldReceive('user')->andThrow(new InvalidStateException);
        Socialite::shouldReceive('driver')->with('microsoft')->andReturn($provider);

        $this->followingRedirects()
            ->get('/auth/microsoft/callback?code=abc&state=xyz')
            ->assertOk()
            ->assertSee('No pudimos completar el inicio de sesión. Inténtalo de nuevo.')
            ->assertSee('Entrar con Microsoft');
    }

    public function test_signed_in_users_see_the_home_page(): void
    {
        $user = User::factory()->create(['name' => 'Ana Pérez']);

        $this->actingAs($user)->get('/')->assertOk()->assertSee('Hola, Ana Pérez');
    }

    public function test_signed_in_users_are_sent_home_from_the_login_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/login')->assertRedirect('/');
    }

    public function test_logout_ends_the_session(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/logout')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_logout_requires_post(): void
    {
        $this->actingAs(User::factory()->create())->get('/logout')->assertMethodNotAllowed();
    }

    public function test_the_redirect_uses_the_organizations_endpoint_and_portal_scopes(): void
    {
        config([
            'services.microsoft.client_id' => 'client-id',
            'services.microsoft.client_secret' => 'client-secret',
            'services.microsoft.redirect' => 'http://localhost/auth/microsoft/callback',
        ]);

        $location = $this->get(route('auth.microsoft.redirect'))->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith('https://login.microsoftonline.com/organizations/oauth2/v2.0/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('client-id', $query['client_id']);
        $this->assertEqualsCanonicalizing(
            ['openid', 'profile', 'email', 'User.Read', 'GroupMember.Read.All'],
            explode(' ', $query['scope']),
        );
    }

    public function test_auth_routes_are_throttled(): void
    {
        $route = app('router')->getRoutes()->getByName('auth.microsoft.callback');

        $this->assertNotNull($route);
        $this->assertTrue(collect($route->gatherMiddleware())->contains(fn ($m) => str_starts_with($m, 'throttle')));
    }
}
