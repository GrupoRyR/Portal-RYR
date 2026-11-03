{{-- Estado de TI administration page (App\Livewire\Admin\ItServiceManager). --}}
<x-layouts.admin title="Estado de TI">
    <div class="flex flex-col gap-1">
        <h1 class="text-[2rem] leading-tight font-bold">Estado de TI</h1>
        <p class="text-[0.9375rem] text-fg-muted">Los servicios de cada empresa que TI reporta a mano, como el correo o internet. Junto con las apps en mantenimiento o con fallas, forman el «Estado de TI» del inicio.</p>
    </div>

    <div class="mt-6">
        <livewire:admin.it-service-manager />
    </div>
</x-layouts.admin>
