{{--
    The favorite star of an app card (x-home.app-card): a form, so it works
    without JavaScript (FavoriteController); resources/js/home.js submits it
    with fetch. The search (`query`) rides along so the page comes back to it.
    `class` places the form in each layout of the card; `compact` is the
    32 px button of «Íconos», in the corner of a small tile, whose
    pseudo-element keeps the 44 px touch target.
--}}
@props(['card', 'query' => '', 'compact' => false])
<form method="POST" action="{{ route($card->favorite ? 'favorites.destroy' : 'favorites.store', $card->slug) }}" class="{{ $attributes->get('class') }}" data-favorite>
    @csrf
    <input type="hidden" name="_method" value="{{ $card->favorite ? 'DELETE' : 'POST' }}">
    @if ($query !== '')
        <input type="hidden" name="q" value="{{ $query }}">
    @endif
    <button type="submit" aria-pressed="{{ $card->favorite ? 'true' : 'false' }}" aria-label="Marcar {{ $card->name }} como favorita" class="group flex {{ $compact ? "relative size-8 before:absolute before:-inset-1.5 before:content-['']" : 'size-touch' }} cursor-pointer items-center justify-center rounded-control text-fg-muted hover:bg-subtle aria-pressed:text-brand-ink">
        <x-home.icon name="star" stroke-width="1.6" class="size-5 group-aria-pressed:fill-current" />
    </button>
</form>
