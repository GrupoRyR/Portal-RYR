{{-- Catalogs administration page (App\Livewire\Admin\ProductManager). --}}
<x-layouts.admin title="Catálogos">
    <div class="flex flex-col gap-1">
        <h1 class="text-[2rem] leading-tight font-bold">Catálogos</h1>
        <p class="text-[0.9375rem] text-fg-muted">Las categorías y los productos de cada empresa, con su foto. Se muestran en «Catálogos y categorías» del inicio.</p>
    </div>

    <div class="mt-6">
        <livewire:admin.product-manager />
    </div>
</x-layouts.admin>
