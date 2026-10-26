{{--
    One app card (docs/diseno/Main.dc.html). The whole card opens the app
    through a stretched link; the star is a separate form so it works
    without JavaScript (resources/js/home.js submits it with fetch).
--}}
@props(['card', 'query' => ''])
@use('App\View\Home\AppCardStatus')
<li
    id="app-{{ $card->slug }}"
    data-app="{{ $card->slug }}"
    data-category="{{ $card->categorySlug }}"
    data-position="{{ $card->position }}"
    data-search="{{ $card->searchText }}"
    @class([
        'relative flex min-h-[168px] flex-col gap-2.5 rounded-card border bg-surface p-4 transition-[border-color,box-shadow] duration-150 hover:shadow-card md:min-h-[188px] md:w-[200px] md:gap-3 md:p-[18px] lg:w-52',
        'border-status-failure-line' => $card->status === AppCardStatus::Failing,
        'border-line hover:border-line-strong' => $card->status !== AppCardStatus::Failing,
    ])
>
    <div class="flex items-start justify-between">
        @if ($card->iconUrl)
            <picture class="shrink-0">
                @if ($card->iconDarkUrl)
                    <source srcset="{{ $card->iconDarkUrl }}" media="(prefers-color-scheme: dark)">
                @endif
                <img src="{{ $card->iconUrl }}" alt="" width="48" height="48" class="size-12 rounded-icon">
            </picture>
        @else
            <span aria-hidden="true" class="flex size-12 shrink-0 items-center justify-center rounded-icon bg-(--app-accent) font-display text-xl font-bold text-white" style="--app-accent: {{ $card->accentColor }}">{{ $card->initial }}</span>
        @endif

        <form method="POST" action="{{ route($card->favorite ? 'favorites.destroy' : 'favorites.store', $card->slug) }}" class="relative z-10 -mt-2.5 -mr-2.5" data-favorite>
            @csrf
            <input type="hidden" name="_method" value="{{ $card->favorite ? 'DELETE' : 'POST' }}">
            @if ($query !== '')
                <input type="hidden" name="q" value="{{ $query }}">
            @endif
            <button type="submit" aria-pressed="{{ $card->favorite ? 'true' : 'false' }}" aria-label="Marcar {{ $card->name }} como favorita" class="group flex size-touch cursor-pointer items-center justify-center rounded-control text-fg-muted hover:bg-subtle aria-pressed:text-brand-accent dark:aria-pressed:text-brand-ink">
                <x-home.icon name="star" stroke-width="1.6" class="size-5 group-aria-pressed:fill-current" />
            </button>
        </form>
    </div>

    <div class="flex flex-col gap-1">
        <a href="{{ $card->href }}"@if ($card->opensInNewTab) target="_blank" rel="noopener"@endif class="text-[0.9375rem] leading-tight font-bold after:absolute after:inset-0 after:rounded-card after:content-[''] focus-visible:outline-none focus-visible:after:outline-2 focus-visible:after:outline-offset-2 focus-visible:after:outline-fg md:text-base">{{ $card->name }}</a>
        <p class="line-clamp-2 text-[0.8125rem] leading-snug text-fg-muted">{{ $card->description }}</p>
    </div>

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
                <x-home.icon :name="$card->status === AppCardStatus::Local ? 'monitor' : 'lock'" stroke-width="2" class="size-3.5" />{{ $card->status->label() }}
            </span>
    @endswitch
</li>
