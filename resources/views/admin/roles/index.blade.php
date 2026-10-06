{{-- Roles administration page (docs/diseno/AdminRoles.dc.html). --}}
<x-layouts.admin title="Roles">
    <div class="flex flex-col gap-1">
        <h1 class="text-[2rem] leading-tight font-bold">Roles</h1>
        <p class="text-[0.9375rem] text-fg-muted">Quién puede administrar qué. Las personas sin rol solo usan el portal.</p>
    </div>

    <div class="mt-6">
        <livewire:admin.role-manager />
    </div>
</x-layouts.admin>
