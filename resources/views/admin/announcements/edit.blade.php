{{-- Announcement editor page: new announcement or an existing one (docs/diseno/AdminAnnouncementEdit.dc.html). --}}
<x-layouts.admin :title="$announcement ? 'Editar anuncio' : 'Nuevo anuncio'">
    <livewire:admin.announcement-editor :announcement="$announcement" :key="'announcement-'.($announcement?->id ?? 'new')" />
</x-layouts.admin>
