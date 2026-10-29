{{-- Status chip of an event: the colors of the announcement chip (x-admin.announcement-status). --}}
@props(['status'])
@php
    $classes = [
        \App\Models\Event::STATUS_PUBLISHED => 'bg-status-active-bg text-status-active',
        \App\Models\Event::STATUS_SCHEDULED => 'bg-status-scheduled-bg text-status-scheduled',
        \App\Models\Event::STATUS_PAST => 'bg-status-neutral-bg text-status-neutral',
        \App\Models\Event::STATUS_WITHDRAWN => 'bg-status-failure-bg text-status-failure',
    ];
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center rounded-lg px-2.5 py-1 text-[0.8125rem] font-semibold whitespace-nowrap '.($classes[$status] ?? $classes[\App\Models\Event::STATUS_PAST])]) }}>{{ \App\Livewire\Admin\EventList::STATUS_LABELS[$status] ?? $status }}</span>
