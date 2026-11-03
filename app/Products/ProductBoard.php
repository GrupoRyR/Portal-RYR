<?php

namespace App\Products;

use App\Models\Company;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read model of one company's catalog for the home screen: its categories
 * in order, each with its published products in order, in two queries
 * however big the catalog is. Categories with nothing published are left
 * out, so a chip never opens an empty list.
 *
 * Both queries are tied to the company, so a product filed under another
 * company's category (which the admin pages never allow) is not shown
 * anywhere.
 */
class ProductBoard
{
    /**
     * @return Collection<int, ProductCategory> Each category has its `products` relation loaded.
     */
    public function forCompany(Company|int $company): Collection
    {
        $companyId = $company instanceof Company ? $company->getKey() : $company;

        return ProductCategory::query()
            ->where('company_id', $companyId)
            ->ordered()
            ->with(['products' => fn ($query) => $query
                ->where('company_id', $companyId)
                ->where('published', true),
            ])
            ->get()
            ->filter(fn (ProductCategory $category) => $category->products->isNotEmpty())
            ->values();
    }
}
