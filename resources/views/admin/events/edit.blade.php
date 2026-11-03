{{-- Event editor page: a new event or an existing one (App\Livewire\Admin\EventEditor). --}}
<x-layouts.admin :title="$event ? 'Editar evento' : 'Nuevo evento'">
    <livewire:admin.event-editor :event="$event" :key="'event-'.($event?->id ?? 'new')" />
</x-layouts.admin>
