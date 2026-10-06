<?php

namespace Tests\Feature\Catalog;

use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FavoriteTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->user = User::factory()->microsoft($this->company)->create();
    }

    public function test_a_user_adds_an_app_to_favorites(): void
    {
        $app = $this->openableApp();

        $this->actingAs($this->user)->postJson("/favoritos/{$app->slug}")
            ->assertOk()
            ->assertExactJson(['favorite' => true]);

        $this->assertTrue($this->user->favoriteApps()->whereKey($app->id)->exists());
    }

    public function test_adding_twice_keeps_one_favorite(): void
    {
        $app = $this->openableApp();

        $this->actingAs($this->user)->postJson("/favoritos/{$app->slug}")->assertOk();
        $this->actingAs($this->user)->postJson("/favoritos/{$app->slug}")->assertOk();

        $this->assertSame(1, $this->user->favoriteApps()->count());
    }

    public function test_a_user_removes_an_app_from_favorites(): void
    {
        $app = $this->openableApp();
        $this->user->favoriteApps()->attach($app);

        $this->actingAs($this->user)->deleteJson("/favoritos/{$app->slug}")
            ->assertOk()
            ->assertExactJson(['favorite' => false]);

        $this->assertSame(0, $this->user->favoriteApps()->count());
    }

    public function test_the_form_without_javascript_adds_and_returns_to_the_card(): void
    {
        $app = $this->openableApp();

        $this->actingAs($this->user)->post("/favoritos/{$app->slug}")
            ->assertRedirect(route('home').'#app-'.$app->slug);

        $this->assertTrue($this->user->favoriteApps()->whereKey($app->id)->exists());
    }

    public function test_the_form_without_javascript_removes_with_method_spoofing(): void
    {
        $app = $this->openableApp();
        $this->user->favoriteApps()->attach($app);

        $this->actingAs($this->user)->post("/favoritos/{$app->slug}", ['_method' => 'DELETE'])
            ->assertRedirect(route('home').'#app-'.$app->slug);

        $this->assertSame(0, $this->user->favoriteApps()->count());
    }

    public function test_the_form_keeps_the_current_search(): void
    {
        $app = $this->openableApp();

        $this->actingAs($this->user)->post("/favoritos/{$app->slug}", ['q' => 'cámaras'])
            ->assertRedirect(route('home', ['q' => 'cámaras']).'#app-'.$app->slug);
    }

    public function test_the_form_ignores_a_search_that_is_not_text(): void
    {
        $app = $this->openableApp();

        $this->actingAs($this->user)->post("/favoritos/{$app->slug}", ['q' => ['x']])
            ->assertRedirect(route('home').'#app-'.$app->slug);
    }

    public function test_favorites_are_per_user(): void
    {
        $app = $this->openableApp();
        $other = User::factory()->microsoft($this->company)->create();
        $other->favoriteApps()->attach($app);

        $this->actingAs($this->user)->deleteJson("/favoritos/{$app->slug}")->assertOk();

        $this->assertSame(1, $other->favoriteApps()->count());
    }

    public function test_an_app_the_user_cannot_open_is_not_found(): void
    {
        $app = App::factory()->forCompanies($this->company)->create();

        $this->actingAs($this->user)->postJson("/favoritos/{$app->slug}")->assertNotFound();
        $this->actingAs($this->user)->deleteJson("/favoritos/{$app->slug}")->assertNotFound();
        $this->assertSame(0, $this->user->favoriteApps()->count());
    }

    public function test_a_hidden_app_is_not_found(): void
    {
        $app = $this->openableApp(['status' => App::STATUS_HIDDEN]);

        $this->actingAs($this->user)->postJson("/favoritos/{$app->slug}")->assertNotFound();
    }

    public function test_guests_cannot_change_favorites(): void
    {
        $app = $this->openableApp();

        $this->postJson("/favoritos/{$app->slug}")->assertUnauthorized();
    }

    public function test_the_routes_require_a_csrf_token(): void
    {
        $app = $this->openableApp();
        $this->actingAs($this->user);

        // The testing kernel skips CSRF; the routes must sit in the "web"
        // group, which verifies it in a real request.
        foreach (['favorites.store', 'favorites.destroy'] as $name) {
            $middleware = app('router')->getRoutes()->getByName($name)->gatherMiddleware();
            $this->assertContains('web', $middleware, $name);
            $this->assertContains('auth', $middleware, $name);
        }

        $this->assertSame(url("/favoritos/{$app->slug}"), route('favorites.store', $app));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function openableApp(array $attributes = []): App
    {
        $app = App::factory()->forCompanies($this->company)->create($attributes);
        AppAccessRule::forCompany($app, $this->company);

        return $app;
    }
}
