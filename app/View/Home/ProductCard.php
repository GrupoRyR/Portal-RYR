<?php

namespace App\View\Home;

use App\Models\Product;
use App\Models\ProductCategory;

/**
 * One product card of «Catálogos y categorías»: photo (lazy, fixed size),
 * name, category and summary; the whole card opens its https link outside
 * the portal when it has one.
 */
final readonly class ProductCard
{
    public function __construct(
        public int $id,
        public int $categoryId,
        public string $categoryName,
        public string $name,
        public ?string $summary,
        public ?string $photoUrl,
        public ?string $linkUrl,
    ) {}

    public static function from(Product $product, ProductCategory $category): self
    {
        $summary = trim((string) $product->summary);

        return new self(
            (int) $product->id,
            (int) $category->id,
            (string) $category->name,
            (string) $product->name,
            $summary === '' ? null : $summary,
            $product->photoUrl(),
            WebLink::href($product->link_url),
        );
    }
}
