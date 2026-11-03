<?php

namespace Tests\Feature\Products;

use App\Audit\AuditEntry;
use App\Audit\AuditPresenter;
use App\Livewire\Admin\ProductManager;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Auditoría rows of the catalogs: a readable subject, Spanish verbs and
 * field labels, and one «moved» entry per reorder.
 */
class ProductAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_category_is_named_and_its_fields_are_labelled(): void
    {
        $category = ProductCategory::factory()->create(['name' => 'Línea smart', 'position' => 1]);

        $created = $this->present($this->latest());
        $this->assertSame('Creó la categoría de productos «Línea smart»', $created->what);
        $this->assertContains('Nombre', array_column($created->changes, 'label'));
        $this->assertContains('Posición', array_column($created->changes, 'label'));
        $this->assertContains('Empresa', array_column($created->changes, 'label'));

        $category->update(['name' => 'Línea inteligente']);
        $updated = $this->present($this->latest());
        $this->assertSame('Cambió la categoría de productos «Línea inteligente»', $updated->what);
        $this->assertSame([['label' => 'Nombre', 'old' => 'Línea smart', 'new' => 'Línea inteligente']], $updated->changes);
    }

    public function test_a_product_is_named_and_its_fields_are_labelled(): void
    {
        $product = Product::factory()->create(['name' => 'Cámara Wi-Fi']);

        $created = $this->present($this->latest());
        $this->assertSame('Creó el producto «Cámara Wi-Fi»', $created->what);
        $labels = array_column($created->changes, 'label');
        foreach (['Nombre', 'Resumen', 'Categoría', 'Publicado', 'Posición'] as $label) {
            $this->assertContains($label, $labels);
        }

        $product->update(['published' => true, 'link_url' => 'https://segurex.com.co/ficha.pdf', 'photo_path' => 'products/1/a.jpg']);
        $changes = collect($this->present($this->latest())->changes)->keyBy('label');
        $this->assertSame(['old' => 'No', 'new' => 'Sí'], ['old' => $changes['Publicado']['old'], 'new' => $changes['Publicado']['new']]);
        $this->assertSame('https://segurex.com.co/ficha.pdf', $changes['Enlace']['new']);
        $this->assertSame('products/1/a.jpg', $changes['Foto']['new']);
    }

    public function test_category_and_company_ids_are_shown_by_name(): void
    {
        $company = Company::factory()->create(['short_name' => 'Segurex']);
        $smart = ProductCategory::factory()->for($company)->create(['name' => 'Línea smart']);
        $comercial = ProductCategory::factory()->for($company)->create(['name' => 'Comercial']);
        $product = Product::factory()->create(['company_id' => $company->id, 'product_category_id' => $smart->id]);

        $created = collect($this->present($this->latest())->changes)->keyBy('label');
        $this->assertSame('Segurex', $created['Empresa']['new']);
        $this->assertSame('Línea smart', $created['Categoría']['new']);

        $product->update(['product_category_id' => $comercial->id]);
        $this->assertSame(
            [['label' => 'Categoría', 'old' => 'Línea smart', 'new' => 'Comercial']],
            $this->present($this->latest())->changes,
        );
    }

    public function test_a_deleted_product_keeps_its_name(): void
    {
        $product = Product::factory()->create(['name' => 'Cloro 5 %']);
        $product->delete();

        $this->assertSame('Borró el producto «Cloro 5 %»', $this->present($this->latest())->what);
    }

    public function test_the_admin_page_writes_one_entry_per_change_with_its_author(): void
    {
        Storage::fake('public');
        $this->seed(RoleSeeder::class);
        $admin = tap(User::factory()->create(['name' => 'Ana Pérez']), fn (User $user) => $user->assignRole('portal_admin'));
        $company = Company::factory()->create();
        $first = ProductCategory::factory()->for($company)->create(['position' => 1]);
        $second = ProductCategory::factory()->for($company)->create(['position' => 2]);
        $product = Product::factory()->create(['company_id' => $company->id, 'product_category_id' => $first->id, 'position' => 1, 'name' => 'Alarma']);
        Product::factory()->create(['company_id' => $company->id, 'product_category_id' => $first->id, 'position' => 2]);
        Activity::query()->delete();

        $manager = Livewire::actingAs($admin)->test(ProductManager::class)->set('companyId', $company->id);

        $manager->call('moveCategory', $second->id, -1);
        $moved = $this->present($this->latest());
        $this->assertSame('Movió la categoría de productos «'.$second->name.'»', $moved->what);
        $this->assertSame([['label' => 'Posición', 'old' => '2', 'new' => '1']], $moved->changes);
        $this->assertSame('Ana Pérez', $moved->who);
        $this->assertSame(1, Activity::count(), 'A reorder writes a single entry.');

        $manager->call('moveProduct', $product->id, 1);
        $this->assertSame('Movió el producto «Alarma»', $this->present($this->latest())->what);
        $this->assertSame(2, Activity::count());

        $manager->call('togglePublished', $product->id);
        $this->assertSame('Cambió el producto «Alarma»', $this->present($this->latest())->what);
        $this->assertSame([['label' => 'Publicado', 'old' => 'No', 'new' => 'Sí']], $this->present($this->latest())->changes);

        $manager->call('deleteProduct', $product->id);
        $this->assertSame('Borró el producto «Alarma»', $this->present($this->latest())->what);
    }

    private function latest(): Activity
    {
        return Activity::query()->where('log_name', ProductCategory::AUDIT_LOG)->latest('id')->firstOrFail();
    }

    private function present(Activity $activity): AuditEntry
    {
        return app(AuditPresenter::class)->activity($activity);
    }
}
