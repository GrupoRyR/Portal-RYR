<?php

namespace Tests\Feature\Products;

use App\Livewire\Admin\ProductManager;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class ProductAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $segurex;

    private Company $sdc;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(RoleSeeder::class);

        $this->segurex = Company::factory()->create(['name' => 'Segurex LATAM SAS', 'short_name' => 'Segurex']);
        $this->sdc = Company::factory()->create(['name' => 'Soluciones y Derivados SAS', 'short_name' => 'SDC']);
        $this->admin = tap(User::factory()->create())->assignRole('portal_admin');
    }

    // --- Access -----------------------------------------------------------

    public function test_only_super_and_portal_admins_reach_the_page(): void
    {
        $super = tap(User::factory()->create())->assignRole('super_admin');
        $editor = tap(User::factory()->create())->assignRole('announcements_editor');

        $this->actingAs($this->admin)->get(route('admin.products.index'))->assertOk()->assertSee('Catálogos');
        $this->actingAs($super)->get(route('admin.products.index'))->assertOk();
        $this->actingAs($editor)->get(route('admin.products.index'))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('admin.products.index'))->assertForbidden();

        auth()->logout();
        $this->get(route('admin.products.index'))->assertRedirect(route('login'));
    }

    public function test_the_component_refuses_people_without_the_role(): void
    {
        Livewire::actingAs(User::factory()->create())->test(ProductManager::class)->assertForbidden();
        Livewire::actingAs(tap(User::factory()->create())->assignRole('announcements_editor'))->test(ProductManager::class)->assertForbidden();
    }

    public function test_a_role_taken_away_stops_the_next_action_and_the_next_render(): void
    {
        $component = $this->manager()->set('newCategory', 'Residencial');

        $this->admin->removeRole('portal_admin');

        $component->call('createCategory')->assertForbidden();
        $this->assertSame(0, ProductCategory::count());
    }

    public function test_the_sidebar_links_to_the_catalogs(): void
    {
        $this->actingAs($this->admin)->get(route('admin.products.index'))
            ->assertSee('href="'.route('admin.products.index').'" aria-current="page"', false);
    }

    // --- Companies --------------------------------------------------------

    public function test_the_page_lists_only_the_selected_companys_catalog(): void
    {
        ProductCategory::factory()->for($this->segurex)->create(['name' => 'Línea smart']);
        ProductCategory::factory()->for($this->sdc)->create(['name' => 'Hipoclorito']);

        $this->manager($this->segurex)->assertSee('Línea smart')->assertDontSee('Hipoclorito');
        $this->manager($this->sdc)->assertSee('Hipoclorito')->assertDontSee('Línea smart');
    }

    public function test_an_unknown_company_falls_back_to_the_first_one(): void
    {
        $this->manager(null)->set('companyId', 999999)->assertSet('companyId', $this->segurex->id);
    }

    // --- Categories -------------------------------------------------------

    public function test_a_category_is_created_at_the_end_of_the_companys_list(): void
    {
        ProductCategory::factory()->for($this->segurex)->create(['name' => 'Residencial', 'position' => 1]);

        $this->manager()->set('newCategory', '  Control   de acceso ')->call('createCategory')->assertHasNoErrors()->assertSet('newCategory', '');

        $created = ProductCategory::where('name', 'Control de acceso')->sole();
        $this->assertSame($this->segurex->id, $created->company_id);
        $this->assertSame(2, $created->position);
    }

    public function test_category_names_are_checked(): void
    {
        ProductCategory::factory()->for($this->segurex)->create(['name' => 'Residencial']);
        ProductCategory::factory()->for($this->sdc)->create(['name' => 'Hipoclorito']);

        $this->manager()->set('newCategory', '   ')->call('createCategory')->assertHasErrors('newCategory');
        $this->manager()->set('newCategory', str_repeat('a', 61))->call('createCategory')->assertHasErrors('newCategory');
        $this->manager()->set('newCategory', 'residencial')->call('createCategory')->assertHasErrors('newCategory');

        // The same name in another company is fine.
        $this->manager()->set('newCategory', 'Hipoclorito')->call('createCategory')->assertHasNoErrors();
        $this->assertSame(1, ProductCategory::where('company_id', $this->segurex->id)->where('name', 'Hipoclorito')->count());
    }

    public function test_a_category_is_renamed(): void
    {
        $category = ProductCategory::factory()->for($this->segurex)->create(['name' => 'Comercial']);

        $this->manager()->call('startRename', $category->id)->assertSet('renameValue', 'Comercial')
            ->set('renameValue', 'Comercial y oficinas')->call('saveRename')->assertHasNoErrors()->assertSet('renamingId', null);

        $this->assertSame('Comercial y oficinas', $category->fresh()->name);
    }

    public function test_categories_move_up_and_down_and_keep_a_clean_numbering(): void
    {
        $a = ProductCategory::factory()->for($this->segurex)->create(['name' => 'A', 'position' => 10]);
        $b = ProductCategory::factory()->for($this->segurex)->create(['name' => 'B', 'position' => 20]);
        $c = ProductCategory::factory()->for($this->segurex)->create(['name' => 'C', 'position' => 30]);

        $this->manager()->call('moveCategory', $c->id, -1);
        $this->assertSame(['A', 'C', 'B'], ProductCategory::ordered()->pluck('name')->all());
        $this->assertSame([1, 2, 3], ProductCategory::ordered()->pluck('position')->all());

        $this->manager()->call('moveCategory', $a->id, -1);
        $this->assertSame(['A', 'C', 'B'], ProductCategory::ordered()->pluck('name')->all());

        $this->manager()->call('moveCategory', $a->id, 1);
        $this->assertSame(['C', 'A', 'B'], ProductCategory::ordered()->pluck('name')->all());
        $this->assertTrue($b->fresh()->position === 3);
    }

    public function test_moving_categories_never_touches_another_company(): void
    {
        $mine = ProductCategory::factory()->for($this->segurex)->create(['position' => 1]);
        ProductCategory::factory()->for($this->segurex)->create(['position' => 2]);
        $other = ProductCategory::factory()->for($this->sdc)->create(['position' => 7]);

        $this->manager()->call('moveCategory', $mine->id, 1);
        $this->manager()->call('moveCategory', $other->id, -1)->assertNotFound();

        $this->assertSame(7, $other->fresh()->position);
    }

    public function test_an_empty_category_is_deleted_and_one_with_products_is_not(): void
    {
        $empty = ProductCategory::factory()->for($this->segurex)->create(['name' => 'Vacía']);
        $used = ProductCategory::factory()->for($this->segurex)->create(['name' => 'Con productos']);
        Product::factory()->create(['company_id' => $this->segurex->id, 'product_category_id' => $used->id]);

        $this->manager()->call('deleteCategory', $empty->id)->assertHasNoErrors();
        $this->manager()->call('deleteCategory', $used->id)->assertHasErrors('categories');

        $this->assertNull(ProductCategory::find($empty->id));
        $this->assertNotNull(ProductCategory::find($used->id));
    }

    // --- Products ---------------------------------------------------------

    public function test_a_product_is_created_hidden_with_its_photo_and_link(): void
    {
        $category = ProductCategory::factory()->for($this->segurex)->create();

        $this->manager()
            ->call('newProduct')
            ->set('productName', 'Cámara Wi-Fi')
            ->set('summary', 'Cámara interior con visión nocturna.')
            ->set('categoryId', $category->id)
            ->set('linkUrl', 'https://segurex.com.co/fichas/camara.pdf')
            ->set('photo', UploadedFile::fake()->image('camara.jpg', 600, 400))
            ->call('saveProduct')
            ->assertHasNoErrors();

        $product = Product::sole();
        $this->assertSame('Cámara Wi-Fi', $product->name);
        $this->assertSame($this->segurex->id, $product->company_id);
        $this->assertSame($category->id, $product->product_category_id);
        $this->assertFalse($product->published);
        $this->assertSame(1, $product->position);
        $this->assertStringStartsWith('products/'.$this->segurex->id.'/', $product->photo_path);
        Storage::disk('public')->assertExists($product->photo_path);
    }

    public function test_a_product_needs_a_name_and_a_category_of_the_same_company(): void
    {
        $mine = ProductCategory::factory()->for($this->segurex)->create();
        $theirs = ProductCategory::factory()->for($this->sdc)->create();

        $this->manager()->call('newProduct')->set('productName', '')->set('categoryId', $mine->id)->call('saveProduct')->assertHasErrors('productName');
        $this->manager()->call('newProduct')->set('productName', 'Alarma')->set('categoryId', $theirs->id)->call('saveProduct')->assertHasErrors('categoryId');
        $this->manager()->call('newProduct')->set('productName', 'Alarma')->set('categoryId', null)->call('saveProduct')->assertHasErrors('categoryId');

        $this->assertSame(0, Product::count());
    }

    public function test_links_that_are_not_https_are_rejected(): void
    {
        $category = ProductCategory::factory()->for($this->segurex)->create();

        foreach (['http://segurex.com.co/ficha.pdf', 'javascript:alert(1)', 'ftp://segurex.com.co/a', 'segurex.com.co/ficha.pdf', 'https://user:pw@segurex.com.co/'] as $link) {
            $this->manager()->call('newProduct')->set('productName', 'Alarma')->set('categoryId', $category->id)
                ->set('linkUrl', $link)->call('saveProduct')->assertHasErrors('linkUrl');
        }

        $this->assertSame(0, Product::count());
    }

    public function test_invalid_uploads_are_rejected_and_nothing_is_stored(): void
    {
        $category = ProductCategory::factory()->for($this->segurex)->create();
        $uploads = [
            'script named png' => UploadedFile::fake()->createWithContent('foto.png', '<?php echo 1;'),
            'pdf' => UploadedFile::fake()->create('ficha.pdf', 100, 'application/pdf'),
            'svg' => UploadedFile::fake()->createWithContent('foto.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 9 9"/>'),
            'too small' => UploadedFile::fake()->image('chica.png', 50, 50),
            'too heavy' => UploadedFile::fake()->image('pesada.jpg', 600, 600)->size(3000),
        ];

        foreach ($uploads as $label => $file) {
            $this->manager()->call('newProduct')->set('productName', 'Alarma')->set('categoryId', $category->id)
                ->set('photo', $file)->call('saveProduct')->assertHasErrors('photo');
        }

        $this->assertSame(0, Product::count());
        $this->assertSame([], Storage::disk('public')->allFiles('products'));
    }

    public function test_replacing_the_photo_removes_the_old_file(): void
    {
        $product = $this->product($this->segurex);
        $old = $this->giveAPhoto($product);

        $this->manager()->call('editProduct', $product->id)
            ->set('photo', UploadedFile::fake()->image('nueva.png', 400, 400))
            ->call('saveProduct')->assertHasNoErrors();

        $new = $product->fresh()->photo_path;
        $this->assertNotSame($old, $new);
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($new);
    }

    public function test_removing_the_photo_deletes_the_file(): void
    {
        $product = $this->product($this->segurex);
        $old = $this->giveAPhoto($product);

        $this->manager()->call('editProduct', $product->id)->set('removePhoto', true)->call('saveProduct')->assertHasNoErrors();

        $this->assertNull($product->fresh()->photo_path);
        Storage::disk('public')->assertMissing($old);
    }

    public function test_saving_without_a_new_photo_keeps_the_current_one(): void
    {
        $product = $this->product($this->segurex);
        $path = $this->giveAPhoto($product);

        $this->manager()->call('editProduct', $product->id)->set('productName', 'Nuevo nombre')->call('saveProduct')->assertHasNoErrors();

        $this->assertSame($path, $product->fresh()->photo_path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_a_failed_save_keeps_the_old_photo_and_stores_no_new_one(): void
    {
        $product = $this->product($this->segurex);
        $path = $this->giveAPhoto($product);

        $this->manager()->call('editProduct', $product->id)
            ->set('linkUrl', 'http://insecure.example/a')
            ->set('photo', UploadedFile::fake()->image('nueva.png', 400, 400))
            ->call('saveProduct')->assertHasErrors('linkUrl');

        $this->assertSame($path, $product->fresh()->photo_path);
        $this->assertCount(1, Storage::disk('public')->allFiles('products'));
    }

    public function test_deleting_a_product_removes_its_photo_and_only_its_photo(): void
    {
        $product = $this->product($this->segurex);
        $other = $this->product($this->segurex);
        $path = $this->giveAPhoto($product);
        $otherPath = $this->giveAPhoto($other);

        $this->manager()->call('deleteProduct', $product->id)->assertHasNoErrors();

        $this->assertNull(Product::find($product->id));
        Storage::disk('public')->assertMissing($path);
        Storage::disk('public')->assertExists($otherPath);
    }

    public function test_a_product_is_published_and_hidden_again(): void
    {
        $product = $this->product($this->segurex);

        $this->manager()->call('togglePublished', $product->id);
        $this->assertTrue($product->fresh()->published);

        $this->manager()->call('togglePublished', $product->id);
        $this->assertFalse($product->fresh()->published);
    }

    public function test_products_move_within_their_category_only(): void
    {
        $category = ProductCategory::factory()->for($this->segurex)->create();
        $other = ProductCategory::factory()->for($this->segurex)->create();
        $a = $this->product($this->segurex, $category, 'A', 1);
        $b = $this->product($this->segurex, $category, 'B', 2);
        $c = $this->product($this->segurex, $other, 'C', 1);

        $this->manager()->call('moveProduct', $b->id, -1);

        $this->assertSame([1, 2], [$b->fresh()->position, $a->fresh()->position]);
        $this->assertSame(1, $c->fresh()->position);
    }

    public function test_moving_a_product_to_another_category_puts_it_last_there(): void
    {
        $from = ProductCategory::factory()->for($this->segurex)->create();
        $to = ProductCategory::factory()->for($this->segurex)->create();
        $this->product($this->segurex, $to, 'Existente', 4);
        $moving = $this->product($this->segurex, $from, 'Mueve', 1);

        $this->manager()->call('editProduct', $moving->id)->set('categoryId', $to->id)->call('saveProduct')->assertHasNoErrors();

        $this->assertSame($to->id, $moving->fresh()->product_category_id);
        $this->assertSame(5, $moving->fresh()->position);
    }

    public function test_another_companys_product_cannot_be_touched_from_this_company(): void
    {
        $theirs = $this->product($this->sdc);
        $path = $this->giveAPhoto($theirs);

        $this->manager($this->segurex)->call('editProduct', $theirs->id)->assertNotFound();
        $this->manager($this->segurex)->call('togglePublished', $theirs->id)->assertNotFound();
        $this->manager($this->segurex)->call('moveProduct', $theirs->id, 1)->assertNotFound();
        $this->manager($this->segurex)->call('deleteProduct', $theirs->id)->assertNotFound();

        $this->assertFalse($theirs->fresh()->published);
        $this->assertNotNull(Product::find($theirs->id));
        Storage::disk('public')->assertExists($path);
    }

    public function test_the_edit_form_cannot_be_pointed_at_another_company_by_switching(): void
    {
        $mine = $this->product($this->segurex);

        $this->manager($this->segurex)->call('editProduct', $mine->id)->set('companyId', $this->sdc->id)->assertSet('editingId', null);
    }

    public function test_a_photo_gd_cannot_decode_is_a_form_error_not_a_crash(): void
    {
        $category = ProductCategory::factory()->for($this->segurex)->create();
        $ihdr = pack('NNCCCCC', 400, 400, 8, 2, 0, 0, 0);
        $broken = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr)).'garbage';

        $this->manager()->call('newProduct')->set('productName', 'Alarma')->set('categoryId', $category->id)
            ->set('photo', UploadedFile::fake()->createWithContent('rota.png', $broken))
            ->call('saveProduct')->assertHasErrors('photo');

        $this->assertSame(0, Product::count());
        $this->assertSame([], Storage::disk('public')->allFiles('products'));
    }

    public function test_a_category_name_taken_in_the_meantime_is_a_form_error_on_create(): void
    {
        // MySQL's collation also ignores accents, and two admins can save at once:
        // the row appears between the check and the insert, so the index decides.
        $company = $this->segurex;
        $raced = false;
        ProductCategory::creating(function (ProductCategory $category) use (&$raced, $company) {
            if (! $raced) {
                $raced = true;
                DB::table('product_categories')->insert([
                    'company_id' => $company->id, 'name' => $category->name, 'position' => 9,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $this->manager()->set('newCategory', 'Electrónica')->call('createCategory')->assertHasErrors('newCategory');

        $this->assertSame(1, ProductCategory::where('name', 'Electrónica')->count());
    }

    public function test_a_category_name_taken_in_the_meantime_is_a_form_error_on_rename(): void
    {
        $category = ProductCategory::factory()->for($this->segurex)->create(['name' => 'Comercial']);
        $company = $this->segurex;
        $raced = false;
        ProductCategory::updating(function (ProductCategory $model) use (&$raced, $company) {
            if (! $raced) {
                $raced = true;
                DB::table('product_categories')->insert([
                    'company_id' => $company->id, 'name' => $model->name, 'position' => 9,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $this->manager()->call('startRename', $category->id)->set('renameValue', 'Oficinas')->call('saveRename')->assertHasErrors('renameValue');

        $this->assertSame('Comercial', $category->fresh()->name);
    }

    public function test_deleting_a_category_checks_and_deletes_inside_one_transaction(): void
    {
        $category = ProductCategory::factory()->for($this->segurex)->create();
        $outside = DB::transactionLevel();
        $levels = [];
        ProductCategory::deleting(function () use (&$levels) {
            $levels[] = DB::transactionLevel();
        });

        $this->manager()->call('deleteCategory', $category->id)->assertHasNoErrors();

        $this->assertNotEmpty($levels);
        $this->assertGreaterThan($outside, $levels[0]);
    }

    // --- Helpers ----------------------------------------------------------

    private function manager(?Company $company = null): Testable
    {
        $component = Livewire::actingAs($this->admin)->test(ProductManager::class);

        return $company === null ? $component : $component->set('companyId', $company->id);
    }

    private function product(Company $company, ?ProductCategory $category = null, string $name = 'Producto', int $position = 1): Product
    {
        $category ??= ProductCategory::factory()->for($company)->create();

        return Product::factory()->create([
            'company_id' => $company->id,
            'product_category_id' => $category->id,
            'name' => $name,
            'position' => $position,
        ]);
    }

    private function giveAPhoto(Product $product): string
    {
        $path = Product::PHOTO_DIR.'/'.$product->company_id.'/'.fake()->unique()->lexify('????????????').'.jpg';
        Storage::disk('public')->put($path, 'jpeg');
        $product->forceFill(['photo_path' => $path])->save();

        return $path;
    }
}
