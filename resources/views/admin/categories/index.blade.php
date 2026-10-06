{{-- Categories administration page (docs/diseno/AdminCategories.dc.html). --}}
<x-layouts.admin title="Categorías">
    <div class="flex flex-col gap-1">
        <h1 class="text-[2rem] leading-tight font-bold">Categorías</h1>
        <p class="text-[0.9375rem] text-fg-muted">Agrupan las apps en el inicio. El orden de aquí es el orden del portal.</p>
    </div>

    <div class="mt-6">
        <livewire:admin.category-manager />
    </div>
</x-layouts.admin>
