<?php

namespace App\View\Home;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Support\Collection;

/**
 * «Catálogos y categorías» of the home (App\Products\ProductBoard): the
 * category filters, «Todas» first, and every published product of the
 * company in category order. Without JavaScript a filter is a link to
 * `/?catalogo={id}#catalogos`; the selected one comes from there (null is
 * «Todas»).
 */
final readonly class ProductShelf
{
    /**
     * @param  list<array{id: int, name: string}>  $categories
     * @param  list<ProductCard>  $products
     */
    public function __construct(
        public array $categories,
        public array $products,
        public ?int $selected,
    ) {}

    /**
     * Null when the company has nothing published. A category that is not
     * one of these (another company's, or none) selects «Todas».
     *
     * @param  Collection<int, ProductCategory>  $board  ProductBoard::forCompany()
     */
    public static function fromBoard(Collection $board, ?string $requested): ?self
    {
        if ($board->isEmpty()) {
            return null;
        }

        $categories = $board->map(fn (ProductCategory $category) => ['id' => (int) $category->id, 'name' => (string) $category->name])->values()->all();
        $ids = array_column($categories, 'id');
        $selected = $requested !== null && ctype_digit($requested) && in_array((int) $requested, $ids, true) ? (int) $requested : null;

        $products = $board->flatMap(fn (ProductCategory $category) => $category->products->map(
            fn (Product $product) => ProductCard::from($product, $category),
        ))->values()->all();

        return new self($categories, $products, $selected);
    }

    public function filterHref(?int $categoryId): string
    {
        return ($categoryId === null ? route('home') : route('home', ['catalogo' => $categoryId])).'#catalogos';
    }

    public function shows(ProductCard $product): bool
    {
        return $this->selected === null || $this->selected === $product->categoryId;
    }
}
