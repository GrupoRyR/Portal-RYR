{{--
    «Catálogos y categorías» of the user's company (odd/tasks/inicio-bloques.md
    H2, App\View\Home\ProductShelf), a glass card of the main column
    (glass.css section 7): category chips, «Todas» first, and the published
    products as glass tiles. Without JavaScript each filter is a link to
    `/?catalogo={id}#catalogos`; resources/js/home.js filters in place.
    Photos load lazily with a fixed box, so nothing moves when they arrive.
--}}
@props(['shelf'])

<section id="catalogos" aria-labelledby="catalogos-titulo" data-catalog {{ $attributes->merge(['class' => 'g-card min-w-0 scroll-mt-6']) }}>
    <div class="g-card__head">
        <span class="g-mark" aria-hidden="true"></span>
        <h2 id="catalogos-titulo">Catálogos y categorías</h2>
    </div>
    <div class="g-card__body">
        <nav aria-label="Categorías del catálogo">
            <ul class="g-quick">
                @foreach ([['id' => null, 'name' => 'Todas'], ...$shelf->categories] as $category)
                    <li>
                        <a href="{{ $shelf->filterHref($category['id']) }}" data-catalog-filter="{{ $category['id'] ?? 'todas' }}" @if ($shelf->selected === $category['id']) aria-current="true" @endif class="g-chip">{{ $category['name'] }}</a>
                    </li>
                @endforeach
            </ul>
        </nav>
        <ul class="g-prods" data-products>
            @foreach ($shelf->products as $product)
                <li data-product="{{ $product->id }}" data-category="{{ $product->categoryId }}" class="min-w-0" @if (! $shelf->shows($product)) hidden @endif>
                    @if ($product->linkUrl)
                        <a href="{{ $product->linkUrl }}" target="_blank" rel="noopener noreferrer" class="g-tile g-prod">
                            <x-home.product-card-body :product="$product" />
                        </a>
                    @else
                        <div class="g-tile g-prod">
                            <x-home.product-card-body :product="$product" />
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
</section>
