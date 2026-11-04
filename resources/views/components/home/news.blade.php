{{--
    «Noticias» of the user's company (odd/tasks/inicio-bloques.md H1), a
    glass card of the main column (glass.css section 7), one tab per kind
    of source that has headlines (App\View\Home\NewsTab). Without
    JavaScript each tab is a link to `/?noticias=…#noticias`;
    resources/js/home.js switches them in place and adds the arrow keys.
    Headlines open outside the portal.
--}}
@props(['tabs'])

<section id="noticias" aria-labelledby="noticias-titulo" data-news {{ $attributes->merge(['class' => 'g-card min-w-0 scroll-mt-6']) }}>
    <div class="g-card__head">
        <span class="g-mark" aria-hidden="true"></span>
        <h2 id="noticias-titulo">Noticias</h2>
    </div>
    <div class="g-card__body flex flex-col gap-3">
        <div role="tablist" aria-labelledby="noticias-titulo" class="g-quick" data-news-tabs>
            @foreach ($tabs as $tab)
                <a role="tab" id="noticias-tab-{{ $tab->key }}" href="{{ $tab->href() }}" aria-selected="{{ $tab->selected ? 'true' : 'false' }}" aria-controls="noticias-{{ $tab->key }}" data-news-tab="{{ $tab->key }}" class="g-chip">{{ $tab->label }}</a>
            @endforeach
        </div>
        @foreach ($tabs as $tab)
            <div role="tabpanel" id="noticias-{{ $tab->key }}" aria-labelledby="noticias-tab-{{ $tab->key }}" data-news-panel="{{ $tab->key }}" @if (! $tab->selected) hidden @endif>
                <ul class="g-rows">
                    @foreach ($tab->headlines as $headline)
                        <li class="g-row flex-col items-start gap-0.5 px-0 py-3 first:pt-1 last:pb-0">
                            @if ($href = \App\View\Home\WebLink::href($headline->link))
                                <a href="{{ $href }}" target="_blank" rel="noopener noreferrer" class="text-[0.9375rem] leading-snug font-bold break-words underline-offset-4 hover:underline">{{ $headline->title }}<span class="sr-only"> (se abre en otra pestaña)</span></a>
                            @else
                                <p class="text-[0.9375rem] leading-snug font-bold break-words">{{ $headline->title }}</p>
                            @endif
                            <p class="text-xs text-fg-muted">{{ $headline->source }} · <time datetime="{{ $headline->publishedAt->toIso8601String() }}">{{ \App\View\Home\NewsTab::dateOf($headline) }}</time></p>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
</section>
