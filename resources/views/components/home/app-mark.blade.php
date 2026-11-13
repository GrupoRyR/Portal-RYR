{{--
    The status of an app in «Íconos» (x-home.app-card): a round icon chip
    of 24 px in a top corner of the tile (glass.css, .g-app__mark), with its
    text for screen readers and as a tooltip, so the tile keeps its height.
    Nothing for an active app (App\View\Home\AppCardStatus::icon()).
--}}
@props(['card'])
@use('App\View\Home\AppCardStatus')
@if ($icon = $card->status->icon())
    @php($text = $card->status->label().($card->statusDetail === null ? '' : ' · '.$card->statusDetail))
    <span data-mark="{{ $icon }}" title="{{ $text }}" @class([
        'g-app__mark',
        'bg-status-maintenance-bg text-status-maintenance' => $card->status === AppCardStatus::Maintenance,
        'bg-status-failure-bg text-status-failure' => $card->status === AppCardStatus::Failing,
        'bg-subtle text-fg-muted' => in_array($card->status, [AppCardStatus::Local, AppCardStatus::OwnLogin], true),
    ])>
        <x-home.icon :name="$icon" stroke-width="2" class="size-3.5" />
        <span class="sr-only">{{ $text }}</span>
    </span>
@endif
