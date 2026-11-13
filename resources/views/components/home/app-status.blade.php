{{--
    The status chip of an app card (x-home.app-card, App\View\Home\AppCardStatus).
--}}
@props(['card'])
@use('App\View\Home\AppCardStatus')
@switch($card->status)
    @case(AppCardStatus::Active)
        <span class="mt-auto flex items-center gap-1.5 text-xs font-semibold text-status-active">
            <span class="size-2 rounded-full bg-status-active-dot" aria-hidden="true"></span>{{ $card->status->label() }}
        </span>
        @break
    @case(AppCardStatus::Maintenance)
        <span class="mt-auto flex items-center gap-1.5 self-start rounded-lg bg-status-maintenance-bg px-2 py-1 text-xs font-semibold text-status-maintenance">
            <x-home.icon name="wrench" stroke-width="2" class="size-3.5" />{{ $card->status->label() }}
        </span>
        @break
    @case(AppCardStatus::Failing)
        <span class="mt-auto flex items-start gap-1.5 self-start rounded-lg bg-status-failure-bg px-2 py-1 text-xs leading-snug font-semibold text-status-failure">
            <x-home.icon name="warning" stroke-width="2.2" class="mt-px size-3.5" /><span>{{ $card->status->label() }} · {{ $card->statusDetail }}</span>
        </span>
        @break
    @default
        <span class="mt-auto flex items-center gap-1.5 text-xs font-semibold text-fg-muted">
            <x-home.icon :name="$card->status->icon()" stroke-width="2" class="size-3.5" />{{ $card->status->label() }}
        </span>
@endswitch
