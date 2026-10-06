{{-- App editor page: new app or an existing one (docs/diseno/AdminAppEdit.dc.html). --}}
<x-layouts.admin :title="$app ? $app->name : 'Nueva aplicación'">
    <livewire:admin.app-editor :app="$app" :key="'app-'.($app?->id ?? 'new')" />
</x-layouts.admin>
