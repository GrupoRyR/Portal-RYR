<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\CategoryManager;
use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\AppCategory;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class CategoryManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AppCategory $ventas;

    private AppCategory $contabilidad;

    private AppCategory $tesoreria;

    private AppCategory $gerencia;

    private AppCategory $compras;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->ventas = AppCategory::factory()->create(['name' => 'Ventas', 'slug' => 'ventas', 'sort_order' => 10]);
        $this->contabilidad = AppCategory::factory()->create(['name' => 'Contabilidad', 'slug' => 'contabilidad', 'sort_order' => 20]);
        $this->gerencia = AppCategory::factory()->create(['name' => 'Gerencia', 'slug' => 'gerencia', 'sort_order' => 30]);
        $this->tesoreria = AppCategory::factory()->create(['name' => 'Tesorería', 'slug' => 'tesoreria', 'sort_order' => 40]);
        $this->compras = AppCategory::factory()->create(['name' => 'Compras', 'slug' => 'compras', 'sort_order' => 50]);

        App::factory()->count(2)->create(['app_category_id' => $this->ventas->id]);
        App::factory()->create(['app_category_id' => $this->contabilidad->id]);
        App::factory()->hidden()->create(['app_category_id' => $this->tesoreria->id]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('portal_admin');
    }

    // --- Access -----------------------------------------------------------

    public function test_the_policy_matrix(): void
    {
        $super = User::factory()->create();
        $super->assignRole('super_admin');

        $this->actingAs($this->admin)->get(route('admin.categories.index'))->assertOk()->assertSee('Categorías');
        $this->actingAs($super)->get(route('admin.categories.index'))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('admin.categories.index'))->assertForbidden();

        auth()->logout();
        $this->get(route('admin.categories.index'))->assertRedirect(route('login'));

        Livewire::actingAs(User::factory()->create())->test(CategoryManager::class)->assertForbidden();
    }

    public function test_the_sidebar_links_to_the_categories(): void
    {
        $this->actingAs($this->admin)->get(route('admin.categories.index'))
            ->assertSee('href="'.route('admin.categories.index').'" aria-current="page"', false);
    }

    // --- List ---------------------------------------------------------------

    public function test_the_list_shows_order_counts_and_collapses_empty_ones(): void
    {
        $this->manager()
            ->assertSeeInOrder(['Ventas', '2 apps', 'Contabilidad', '1 app', 'Tesorería', '1 app'])
            ->assertSee('2 vacías')
            ->assertSee('Gerencia · Compras')
            ->assertSee('Las vacías no se muestran')
            ->assertSee('Borrar una categoría')
            ->assertSee('aria-label="Subir Ventas" disabled', false)
            ->assertSee('aria-label="Bajar Tesorería" disabled', false)
            ->assertDontSee('aria-label="Subir Gerencia"', false);
    }

    public function test_empty_categories_can_be_shown(): void
    {
        $html = $this->manager()
            ->call('toggleEmpty')
            ->assertSee('aria-label="Bajar Compras" disabled', false)
            ->assertSee('Ocultar vacías')
            ->html();

        $positions = array_map(fn (string $text) => strpos($html, $text), ['Ventas', 'Contabilidad', 'Gerencia', 'Vacía', 'Tesorería', 'Compras']);
        $this->assertNotContains(false, $positions);
        $this->assertSame($positions, collect($positions)->sort()->values()->all());
    }

    // --- Order --------------------------------------------------------------

    public function test_moving_persists_the_order(): void
    {
        $this->manager()->call('moveUp', $this->contabilidad->id);

        $this->assertOrder(['ventas' => 2, 'contabilidad' => 1]);
        $this->assertSame(['contabilidad', 'ventas', 'gerencia', 'tesoreria', 'compras'], AppCategory::query()->ordered()->pluck('slug')->all());

        $this->manager()->call('moveDown', $this->contabilidad->id);
        $this->assertSame(['ventas', 'contabilidad', 'gerencia', 'tesoreria', 'compras'], AppCategory::query()->ordered()->pluck('slug')->all());
    }

    public function test_moving_skips_collapsed_empty_categories(): void
    {
        // Gerencia (empty, collapsed) sits between Contabilidad and Tesorería.
        $this->manager()->call('moveUp', $this->tesoreria->id);

        $this->assertSame(['ventas', 'tesoreria', 'gerencia', 'contabilidad', 'compras'], AppCategory::query()->ordered()->pluck('slug')->all());
    }

    public function test_the_first_and_last_cannot_move_further(): void
    {
        $this->manager()->call('moveUp', $this->ventas->id)->call('moveDown', $this->tesoreria->id);

        $this->assertSame(['ventas', 'contabilidad', 'gerencia', 'tesoreria', 'compras'], AppCategory::query()->ordered()->pluck('slug')->all());
    }

    public function test_the_home_screen_follows_the_new_order(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->microsoft($company)->create();
        foreach ([$this->ventas, $this->contabilidad] as $category) {
            $app = App::factory()->forCompanies($company)->create(['app_category_id' => $category->id]);
            AppAccessRule::forCompany($app, $company);
        }

        $html = $this->actingAs($user)->get('/')->getContent();
        $this->assertLessThan(strpos($html, 'data-category="contabilidad"'), strpos($html, 'data-category="ventas"'));

        $this->manager()->call('moveUp', $this->contabilidad->id);

        $html = $this->actingAs($user)->get('/')->getContent();
        $this->assertLessThan(strpos($html, 'data-category="ventas"'), strpos($html, 'data-category="contabilidad"'));
    }

    // --- Rename -------------------------------------------------------------

    public function test_a_category_is_renamed_and_keeps_its_slug(): void
    {
        $this->manager()
            ->call('startRename', $this->tesoreria->id)
            ->assertSet('renameValue', 'Tesorería')
            ->assertSee('Nuevo nombre de la categoría')
            ->set('renameValue', ' Tesorería y pagos ')
            ->call('saveRename')
            ->assertHasNoErrors()
            ->assertSet('renamingId', null);

        $this->assertSame('Tesorería y pagos', $this->tesoreria->fresh()->name);
        $this->assertSame('tesoreria', $this->tesoreria->fresh()->slug);
    }

    public function test_a_rename_must_be_unique_and_not_empty(): void
    {
        $this->manager()->call('startRename', $this->tesoreria->id)->set('renameValue', 'ventas')->call('saveRename')
            ->assertHasErrors('renameValue')
            ->assertSee('Ya hay una categoría con ese nombre.');
        $this->manager()->call('startRename', $this->tesoreria->id)->set('renameValue', '')->call('saveRename')->assertHasErrors('renameValue');

        // Same name with another case is fine for the category itself.
        $this->manager()->call('startRename', $this->tesoreria->id)->set('renameValue', 'TESORERÍA')->call('saveRename')->assertHasNoErrors();
    }

    public function test_cancelling_a_rename_changes_nothing(): void
    {
        $this->manager()->call('startRename', $this->ventas->id)->set('renameValue', 'Otra')->call('cancelRename')->assertSet('renamingId', null);

        $this->assertSame('Ventas', $this->ventas->fresh()->name);
    }

    // --- Create -------------------------------------------------------------

    public function test_a_category_is_added_last_with_a_generated_slug(): void
    {
        $this->manager()->set('newName', 'Recursos Humanos')->call('create')->assertHasNoErrors()->assertSet('newName', '');

        $category = AppCategory::where('name', 'Recursos Humanos')->sole();
        $this->assertSame('recursos-humanos', $category->slug);
        $this->assertSame('recursos-humanos', AppCategory::query()->ordered()->get()->last()->slug);
    }

    public function test_a_new_name_must_be_unique_and_its_slug_never_collides(): void
    {
        $this->manager()->set('newName', 'VENTAS')->call('create')->assertHasErrors('newName');
        $this->manager()->set('newName', '')->call('create')->assertHasErrors('newName');

        AppCategory::factory()->create(['name' => 'Diseño viejo', 'slug' => 'diseno']);
        $this->manager()->set('newName', 'Diseño')->call('create')->assertHasNoErrors();

        $this->assertSame('diseno-2', AppCategory::where('name', 'Diseño')->sole()->slug);
    }

    // --- Delete -------------------------------------------------------------

    public function test_an_empty_category_is_deleted(): void
    {
        $this->manager()->call('delete', $this->gerencia->id)->assertHasNoErrors()->assertSee('Se borró «Gerencia».');

        $this->assertModelMissing($this->gerencia);
    }

    public function test_a_category_with_apps_is_not_deleted_even_if_they_are_hidden(): void
    {
        $this->manager()->call('delete', $this->tesoreria->id)
            ->assertHasErrors('categories')
            ->assertSee('No se puede borrar «Tesorería»: tiene 1 app. Primero muévela a otra categoría.');
        $this->manager()->call('delete', $this->ventas->id)
            ->assertSee('No se puede borrar «Ventas»: tiene 2 apps. Primero muévelas a otra categoría.');

        $this->assertModelExists($this->tesoreria);
        $this->assertModelExists($this->ventas);
    }

    // --- Activity -----------------------------------------------------------

    public function test_changes_are_logged(): void
    {
        $before = (int) Activity::max('id');

        $this->manager()
            ->set('newName', 'Recursos Humanos')->call('create')
            ->call('startRename', $this->ventas->id)->set('renameValue', 'Ventas y pedidos')->call('saveRename')
            ->call('moveUp', $this->contabilidad->id)
            ->call('delete', $this->gerencia->id);

        $log = Activity::where('log_name', AppCategory::AUDIT_LOG)->where('id', '>', $before)->orderBy('id')->get();

        $this->assertTrue($log->every(fn (Activity $entry) => $entry->causer?->is($this->admin)));
        $this->assertSame(['created', 'updated', 'category.moved', 'deleted'], $log->pluck('event')->all());
        $this->assertSame(['name' => 'Ventas y pedidos'], $log[1]->properties['attributes']);
        $this->assertSame(['position' => 2], $log[2]->properties['old']);
        $this->assertSame(['position' => 1], $log[2]->properties['attributes']);
    }

    private function manager(): Testable
    {
        return Livewire::actingAs($this->admin)->test(CategoryManager::class);
    }

    /**
     * @param  array<string, int>  $positions  slug => 1-based position
     */
    private function assertOrder(array $positions): void
    {
        $order = AppCategory::query()->ordered()->pluck('slug')->all();

        foreach ($positions as $slug => $position) {
            $this->assertSame($position, array_search($slug, $order, true) + 1, "{$slug} should be at {$position}.");
        }
    }
}
