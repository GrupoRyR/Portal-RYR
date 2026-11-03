<?php

namespace App\Livewire\Admin;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Products\ProductLinkPolicy;
use App\Products\ProductPhotoStore;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Administración › Catálogos: the categories and products of one company
 * (the home screen shows them as «Catálogos y categorías»). Every action
 * and every render re-checks the policies, and every record is looked up
 * inside the selected company, so an id from another company is a 404.
 *
 * Products are created hidden and published from the list. A category can
 * only be deleted when it has no products, so no photo is left behind.
 */
class ProductManager extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    #[Url(as: 'empresa')]
    public ?int $companyId = null;

    // Categories.
    public string $newCategory = '';

    #[Locked]
    public ?int $renamingId = null;

    public string $renameValue = '';

    // Product form.
    #[Locked]
    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public string $productName = '';

    public string $summary = '';

    public ?int $categoryId = null;

    public string $linkUrl = '';

    public ?TemporaryUploadedFile $photo = null;

    public bool $removePhoto = false;

    /** Confirmation of the last change, read by screen readers. */
    #[Locked]
    public string $notice = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Product::class);

        $this->companyId = $this->resolveCompanyId($this->companyId);
    }

    public function updatedCompanyId(): void
    {
        $this->authorize('viewAny', Product::class);

        $this->companyId = $this->resolveCompanyId($this->companyId);
        $this->cancelRename();
        $this->cancelProduct();
        $this->notice = '';
    }

    // --- Categories -------------------------------------------------------

    public function createCategory(): void
    {
        $this->authorize('create', ProductCategory::class);

        $company = $this->company();
        $name = $this->checkCategoryName('newCategory', $this->newCategory, $company);

        try {
            ProductCategory::create([
                'company_id' => $company->id,
                'name' => $name,
                'position' => (int) ProductCategory::query()->where('company_id', $company->id)->max('position') + 1,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw self::nameTaken('newCategory');
        }

        $this->newCategory = '';
        $this->notice = "Se agregó «{$name}» al final de las categorías.";
    }

    public function startRename(int $id): void
    {
        $category = $this->category($id);
        $this->authorize('update', $category);

        $this->renamingId = $category->id;
        $this->renameValue = $category->name;
        $this->resetValidation();
    }

    public function cancelRename(): void
    {
        $this->renamingId = null;
        $this->renameValue = '';
        $this->resetValidation('renameValue');
    }

    public function saveRename(): void
    {
        if ($this->renamingId === null) {
            return;
        }

        $category = $this->category($this->renamingId);
        $this->authorize('update', $category);

        $name = $this->checkCategoryName('renameValue', $this->renameValue, $this->company(), $category->id);

        try {
            $category->update(['name' => $name]);
        } catch (UniqueConstraintViolationException) {
            throw self::nameTaken('renameValue');
        }

        $this->cancelRename();
        $this->notice = "Se cambió el nombre a «{$name}».";
    }

    public function moveCategory(int $id, int $step): void
    {
        $category = $this->category($id);
        $this->authorize('update', $category);

        $order = $this->categories()->pluck('id')->all();
        $from = array_search($category->id, $order, true);
        $to = $from + ($step < 0 ? -1 : 1);

        if (! isset($order[$to])) {
            return;
        }

        [$order[$from], $order[$to]] = [$order[$to], $order[$from]];

        $this->renumber(ProductCategory::class, $order);

        $category->audit('product_category.moved', [
            'old' => ['position' => $from + 1],
            'attributes' => ['position' => $to + 1],
        ]);

        $this->notice = "«{$category->name}» quedó en la posición ".($to + 1).'.';
    }

    public function deleteCategory(int $id): void
    {
        $category = $this->category($id);
        $this->authorize('delete', $category);

        // Lock the category row while counting, so a product added at the
        // same moment waits (its foreign key needs this row) and is never
        // removed by the cascade with its photo left behind.
        $count = DB::transaction(function () use ($category) {
            ProductCategory::query()->whereKey($category->id)->lockForUpdate()->first();

            $count = Product::query()->where('product_category_id', $category->id)->count();

            if ($count === 0) {
                $category->delete();
            }

            return $count;
        });

        if ($count > 0) {
            $label = $count === 1 ? '1 producto' : "{$count} productos";
            $them = $count === 1 ? 'muévelo' : 'muévelos';

            throw ValidationException::withMessages([
                'categories' => "No se puede borrar «{$category->name}»: tiene {$label}. Primero {$them} a otra categoría o bórralos.",
            ]);
        }

        if ($this->renamingId === $id) {
            $this->cancelRename();
        }

        $this->notice = "Se borró «{$category->name}».";
    }

    // --- Products ---------------------------------------------------------

    public function newProduct(): void
    {
        $this->authorize('create', Product::class);

        $this->cancelProduct();
        $this->showForm = true;
        $this->categoryId = $this->categories()->first()?->id;
    }

    public function editProduct(int $id): void
    {
        $product = $this->product($id);
        $this->authorize('update', $product);

        $this->cancelProduct();
        $this->showForm = true;
        $this->editingId = $product->id;
        $this->productName = $product->name;
        $this->summary = (string) $product->summary;
        $this->categoryId = $product->product_category_id;
        $this->linkUrl = (string) $product->link_url;
    }

    public function cancelProduct(): void
    {
        $this->reset('showForm', 'editingId', 'productName', 'summary', 'categoryId', 'linkUrl', 'photo', 'removePhoto');
        $this->resetValidation();
    }

    public function saveProduct(ProductPhotoStore $photos): void
    {
        $product = $this->editingId === null ? null : $this->product($this->editingId);
        $this->authorize($product === null ? 'create' : 'update', $product ?? Product::class);

        $company = $this->company();
        $data = $this->validate($this->rules(), $this->messages());

        $name = trim(preg_replace('/\s+/u', ' ', $data['productName']) ?? '');
        try {
            $newPath = $this->photo instanceof TemporaryUploadedFile ? $photos->store($company->id, $this->photo) : null;
        } catch (InvalidArgumentException) {
            // Looked fine in its header, but GD cannot decode it (truncated, animated...).
            throw ValidationException::withMessages(['photo' => 'No se pudo leer la foto. Expórtala de nuevo como JPG, PNG o WebP.']);
        }

        try {
            $attributes = [
                'name' => $name,
                'summary' => trim((string) $data['summary']) ?: null,
                'link_url' => trim((string) $data['linkUrl']) ?: null,
                'product_category_id' => (int) $data['categoryId'],
            ];

            if ($product === null) {
                $product = Product::create($attributes + [
                    'company_id' => $company->id,
                    'photo_path' => $newPath,
                    'position' => $this->nextProductPosition((int) $data['categoryId']),
                    'published' => false,
                ]);
                $oldPath = null;
            } else {
                $oldPath = $product->photo_path;

                if ($product->product_category_id !== $attributes['product_category_id']) {
                    $attributes['position'] = $this->nextProductPosition($attributes['product_category_id']);
                }

                if ($newPath !== null) {
                    $attributes['photo_path'] = $newPath;
                } elseif ($this->removePhoto) {
                    $attributes['photo_path'] = null;
                }

                $product->update($attributes);
            }
        } catch (Throwable $e) {
            $photos->delete($newPath);

            throw $e;
        }

        $photos->deleteReplaced($oldPath, $product->photo_path);

        $created = $this->editingId === null;
        $this->cancelProduct();
        $this->notice = $created
            ? "Se creó «{$name}». Está oculto hasta que lo publiques."
            : "Se guardaron los cambios de «{$name}».";
    }

    public function togglePublished(int $id): void
    {
        $product = $this->product($id);
        $this->authorize('update', $product);

        $product->update(['published' => ! $product->published]);

        $this->notice = $product->published
            ? "«{$product->name}» ya se muestra en el catálogo."
            : "«{$product->name}» quedó oculto.";
    }

    public function moveProduct(int $id, int $step): void
    {
        $product = $this->product($id);
        $this->authorize('update', $product);

        $order = Product::query()
            ->where('company_id', $product->company_id)
            ->where('product_category_id', $product->product_category_id)
            ->orderBy('position')->orderBy('id')
            ->pluck('id')->all();
        $from = array_search($product->id, $order, true);
        $to = $from + ($step < 0 ? -1 : 1);

        if (! isset($order[$to])) {
            return;
        }

        [$order[$from], $order[$to]] = [$order[$to], $order[$from]];

        $this->renumber(Product::class, $order);

        $product->audit('product.moved', [
            'old' => ['position' => $from + 1],
            'attributes' => ['position' => $to + 1],
        ]);

        $this->notice = "«{$product->name}» quedó en la posición ".($to + 1).' de su categoría.';
    }

    public function deleteProduct(int $id, ProductPhotoStore $photos): void
    {
        $product = $this->product($id);
        $this->authorize('delete', $product);

        $product->delete();
        $photos->delete($product->photo_path);

        if ($this->editingId === $id) {
            $this->cancelProduct();
        }

        $this->notice = "Se borró «{$product->name}».";
    }

    public function render(): View
    {
        $this->authorize('viewAny', Product::class);

        $companies = Company::query()->orderBy('name')->get(['id', 'name', 'short_name', 'is_active']);
        $company = $companies->firstWhere('id', $this->companyId);

        $categories = $company === null
            ? new Collection
            : ProductCategory::query()->where('company_id', $company->id)->ordered()
                ->with(['products' => fn ($query) => $query->where('company_id', $company->id)])
                ->get();

        return view('livewire.admin.product-manager', [
            'companies' => $companies,
            'company' => $company,
            'categories' => $categories,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'productName' => ['required', 'string', 'max:'.Product::NAME_MAX],
            'summary' => ['nullable', 'string', 'max:'.Product::SUMMARY_MAX],
            'categoryId' => ['required', 'integer', Rule::exists('product_categories', 'id')->where('company_id', $this->companyId)],
            'linkUrl' => ['nullable', 'string', 'max:'.Product::LINK_MAX, function (string $attribute, mixed $value, Closure $fail) {
                if ($value !== null && $value !== '' && ! ProductLinkPolicy::allows($value)) {
                    $fail('Escribe un enlace completo que empiece por https://, por ejemplo https://sitio.com/ficha.pdf.');
                }
            }],
            'photo' => ['nullable', 'file', 'max:'.ProductPhotoStore::MAX_KILOBYTES, 'extensions:'.implode(',', ProductPhotoStore::EXTENSIONS), function (string $attribute, mixed $value, Closure $fail) {
                if ($value instanceof TemporaryUploadedFile && ($problem = app(ProductPhotoStore::class)->problem($value)) !== null) {
                    $fail($problem);
                }
            }],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'productName.required' => 'Escribe el nombre del producto.',
            'productName.max' => 'Usa como máximo '.Product::NAME_MAX.' caracteres.',
            'summary.max' => 'Usa como máximo '.Product::SUMMARY_MAX.' caracteres.',
            'categoryId.required' => 'Elige una categoría.',
            'categoryId.exists' => 'Elige una categoría de esta empresa.',
            'linkUrl.max' => 'El enlace es demasiado largo.',
            'photo.max' => 'La foto pesa más de 2 MB.',
            'photo.extensions' => 'Sube una foto JPG, PNG o WebP.',
            'photo.file' => 'Sube una foto JPG, PNG o WebP.',
        ];
    }

    /**
     * The selected company, or a 404 when it does not exist.
     */
    private function company(): Company
    {
        $company = $this->companyId === null ? null : Company::find($this->companyId);

        abort_if($company === null, 404);

        return $company;
    }

    private function resolveCompanyId(?int $id): ?int
    {
        if ($id !== null && Company::query()->whereKey($id)->exists()) {
            return $id;
        }

        return Company::query()->orderBy('name')->value('id');
    }

    private function category(int $id): ProductCategory
    {
        $category = ProductCategory::query()->where('company_id', $this->company()->id)->find($id);

        abort_if($category === null, 404);

        return $category;
    }

    private function product(int $id): Product
    {
        $product = Product::query()->where('company_id', $this->company()->id)->find($id);

        abort_if($product === null, 404);

        return $product;
    }

    /**
     * @return Collection<int, ProductCategory>
     */
    private function categories(): Collection
    {
        return ProductCategory::query()->where('company_id', $this->company()->id)->ordered()->get();
    }

    private function nextProductPosition(int $categoryId): int
    {
        return (int) Product::query()->where('product_category_id', $categoryId)->max('position') + 1;
    }

    /**
     * Numbers the rows 1..n in the given order, without one audit entry per
     * row (the caller writes a single «moved» entry).
     *
     * @param  class-string<ProductCategory|Product>  $model
     * @param  list<int>  $order
     */
    private function renumber(string $model, array $order): void
    {
        DB::transaction(function () use ($model, $order) {
            foreach ($order as $position => $id) {
                $model::query()->whereKey($id)->update(['position' => $position + 1]);
            }
        });
    }

    private function checkCategoryName(string $field, string $value, Company $company, ?int $except = null): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        if ($name === '') {
            throw ValidationException::withMessages([$field => 'Escribe el nombre de la categoría.']);
        }

        if (mb_strlen($name) > ProductCategory::NAME_MAX) {
            throw ValidationException::withMessages([$field => 'Usa como máximo '.ProductCategory::NAME_MAX.' caracteres.']);
        }

        $taken = ProductCategory::query()
            ->where('company_id', $company->id)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->pluck('name')
            ->contains(fn (string $existing) => mb_strtolower($existing) === mb_strtolower($name));

        if ($taken) {
            throw self::nameTaken($field);
        }

        return $name;
    }

    /**
     * MySQL's collation also ignores accents («Electronica» and
     * «Electrónica» collide) and two admins can save at once, so the
     * unique index has the last word: it ends here, as a form error.
     */
    private static function nameTaken(string $field): ValidationException
    {
        return ValidationException::withMessages([$field => 'Esta empresa ya tiene una categoría con ese nombre.']);
    }
}
