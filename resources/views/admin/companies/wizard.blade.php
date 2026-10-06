{{-- New-company wizard page (docs/diseno/AdminWizard.dc.html). --}}
<x-layouts.admin :title="$company ? 'Asistente · '.$company->name : 'Nueva empresa'">
    <div class="flex flex-col gap-1.5">
        <nav aria-label="Ruta" class="flex items-center gap-2 text-sm text-fg-muted">
            <a href="{{ route('admin.companies.index') }}" class="font-semibold underline-offset-4 hover:text-fg hover:underline">Empresas</a>
            <span aria-hidden="true">/</span>
            <span aria-current="page">{{ $company ? $company->name : 'Nueva empresa' }}</span>
        </nav>
        <h1 class="text-[2rem] leading-tight font-bold">Nueva empresa</h1>
    </div>

    <div class="mt-6">
        @if ($company)
            <livewire:admin.company-wizard :company="$company" :key="'wizard-'.$company->id" />
        @else
            <livewire:admin.company-wizard />
        @endif
    </div>
</x-layouts.admin>
