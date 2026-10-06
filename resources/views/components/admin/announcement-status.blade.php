{{-- Status chip of an announcement (docs/diseno/AdminAnnouncements.dc.html). --}}
@props(['status'])
@php
    $classes = [
        \App\Models\Announcement::STATUS_PUBLISHED => 'bg-status-active-bg text-status-active',
        \App\Models\Announcement::STATUS_SCHEDULED => 'bg-status-scheduled-bg text-status-scheduled',
        \App\Models\Announcement::STATUS_EXPIRED => 'bg-status-neutral-bg text-status-neutral',
        \App\Models\Announcement::STATUS_WITHDRAWN => 'bg-status-failure-bg text-status-failure',
    ];
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center rounded-lg px-2.5 py-1 text-[0.8125rem] font-semibold whitespace-nowrap '.($classes[$status] ?? $classes[\App\Models\Announcement::STATUS_EXPIRED])]) }}>{{ \App\View\Admin\AnnouncementAdminRow::STATUS_LABELS[$status] ?? $status }}</span>
