<?php

namespace Tests\Feature\Home;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The view of «Mis aplicaciones» each account keeps (odd/tasks/vistas-apps.md
 * V1): `users.apps_view`, saved by `PUT /inicio/vista` from the selector
 * of the card, which works without JavaScript.
 */
class AppsViewPreferenceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['name' => 'Segurex Latam']);
        $this->user = User::factory()->microsoft($this->company)->create(['name' => 'Ana María Pérez']);
    }

    public function test_the_column_exists_and_is_empty_by_default(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'apps_view'));
        $this->assertNull($this->user->fresh()->apps_view);
    }

    public function test_the_view_is_not_mass_assignable(): void
    {
        $user = new User;
        $user->fill(['name' => 'Luis', 'apps_view' => 'list']);

        $this->assertSame('Luis', $user->name);
        $this->assertArrayNotHasKey('apps_view', $user->getAttributes());
    }

    #[DataProvider('views')]
    public function test_each_view_is_saved_and_the_card_is_shown_again(string $view): void
    {
        $this->actingAs($this->user)
            ->put(route('home.apps-view'), ['view' => $view])
            ->assertRedirect(route('home').'#mis-aplicaciones')
            ->assertSessionHasNoErrors();

        $this->assertSame($view, $this->user->fresh()->apps_view);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function views(): array
    {
        return [
            'categories' => ['categories'],
            'all' => ['all'],
            'list' => ['list'],
            'launcher' => ['launcher'],
        ];
    }

    public function test_saving_keeps_the_search(): void
    {
        $this->actingAs($this->user)
            ->put(route('home.apps-view'), ['view' => 'list', 'q' => 'cartera'])
            ->assertRedirect(route('home', ['q' => 'cartera']).'#mis-aplicaciones');

        $this->actingAs($this->user)
            ->put(route('home.apps-view'), ['view' => 'list', 'q' => '   '])
            ->assertRedirect(route('home').'#mis-aplicaciones');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    #[DataProvider('invalidInput')]
    public function test_an_unknown_view_is_refused_and_nothing_is_saved(array $input): void
    {
        $this->user->forceFill(['apps_view' => 'launcher'])->save();

        $this->actingAs($this->user)
            ->put(route('home.apps-view'), $input)
            ->assertRedirect(route('home').'#mis-aplicaciones')
            ->assertSessionHasErrors('view');

        $this->assertSame('launcher', $this->user->fresh()->apps_view);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidInput(): array
    {
        return [
            'unknown' => [['view' => 'mosaic']],
            'upper case' => [['view' => 'LIST']],
            'empty' => [['view' => '']],
            'missing' => [[]],
            'a list' => [['view' => ['list']]],
            'too long' => [['view' => str_repeat('a', 300)]],
        ];
    }

    public function test_a_json_request_with_an_unknown_view_gets_a_422(): void
    {
        $this->actingAs($this->user)
            ->putJson(route('home.apps-view'), ['view' => 'mosaic'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('view');

        $this->assertNull($this->user->fresh()->apps_view);
    }

    public function test_each_account_keeps_its_own_view(): void
    {
        $other = User::factory()->microsoft($this->company)->create();

        $this->actingAs($this->user)->put(route('home.apps-view'), ['view' => 'all']);

        $this->assertSame('all', $this->user->fresh()->apps_view);
        $this->assertNull($other->fresh()->apps_view);
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->put(route('home.apps-view'), ['view' => 'list'])->assertRedirect(route('login'));
    }

    public function test_saving_needs_the_csrf_token(): void
    {
        // The testing kernel skips CSRF; check it as a real request would.
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });

        $this->actingAs($this->user)->put(route('home.apps-view'), ['view' => 'list'])->assertStatus(419);

        $this->assertNull($this->user->fresh()->apps_view);
    }

    public function test_the_route_is_put_and_throttled(): void
    {
        $route = Route::getRoutes()->getByName('home.apps-view');

        $this->assertSame('inicio/vista', $route->uri());
        $this->assertSame(['PUT'], $route->methods());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('throttle:20,1', $route->gatherMiddleware());
    }
}
