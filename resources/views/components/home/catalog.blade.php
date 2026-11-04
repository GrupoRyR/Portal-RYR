{{--
    «Catálogos y categorías» of the user's company (odd/tasks/inicio-bloques.md
    H2, App\View\Home\ProductShelf): category filters, «Todas» first, and the
    published product cards. Without JavaScript each filter is a link to
    `/?catalogo={id}#catalogos`; resources/js/home.js filters in place.
    Photos load lazily with a fixed box, so nothing moves when they arrive.
--}}
@props(['shelf'])

<section id="catalogos" aria-labelledby="catalogos-titulo" data-catalog {{ $attributes->merge(['class' => 'flex min-w-0 scroll-mt-6 flex-col gap-3 md:gap-3.5']) }}>
    <h2 id="catalogos-titulo" class="font-sans text-sm font-bold tracking-normal text-brand-text">Catálogos y categorías</h2>
    <nav aria-label="Categorías del catálogo">
        <ul class="flex flex-wrap gap-2">
            @foreach ([['id' => null, 'name' => 'Todas'], ...$shelf->categories] as $category)
                <li>
                    <a href="{{ $shelf->filterHref($category['id']) }}" data-catalog-filter="{{ $category['id'] ?? 'todas' }}" @if ($shelf->selected === $category['id']) aria-current="true" @endif class="inline-flex min-h-touch items-center rounded-full border border-line-strong px-4 text-sm font-semibold hover:bg-subtle aria-[current=true]:border-brand-ink aria-[current=true]:bg-brand-tint aria-[current=true]:text-brand-ink">{{ $category['name'] }}</a>
                </li>
            @endforeach
        </ul>
    </nav>
    <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:gap-4 xl:grid-cols-4" data-products>
        @foreach ($shelf->products as $product)
            <li data-product="{{ $product->id }}" data-category="{{ $product->categoryId }}" class="min-w-0" @if (! $shelf->shows($product)) hidden @endif>
                @if ($product->linkUrl)
                    <a href="{{ $product->linkUrl }}" target="_blank" rel="noopener noreferrer" class="flex h-full flex-col overflow-hidden rounded-card border border-line bg-surface hover:border-line-strong">
                        <x-home.product-card-body :product="$product" />
                    </a>
                @else
                    <div class="flex h-full flex-col overflow-hidden rounded-card border border-line bg-surface">
                        <x-home.product-card-body :product="$product" />
                    </div>
                @endif
            </li>
        @endforeach
    </ul>
</section>
