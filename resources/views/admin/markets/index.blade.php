{{-- Mercados administration page (App\Livewire\Admin\MarketManager). --}}
<x-layouts.admin title="Mercados">
    <div class="flex flex-col gap-1">
        <h1 class="text-[2rem] leading-tight font-bold">Mercados</h1>
        <p class="text-[0.9375rem] text-fg-muted">Qué ve cada empresa en la banda «Mercados» del inicio: los insumos automáticos que elijas y los precios ingresados a mano, como las cotizaciones de proveedores.</p>
    </div>

    <div class="mt-6">
        <livewire:admin.market-manager />
    </div>
</x-layouts.admin>
