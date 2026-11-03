{{--
    «Noticias» of the user's company (odd/tasks/inicio-bloques.md H1), one
    tab per kind of source that has headlines (App\View\Home\NewsTab).
    Without JavaScript each tab is a link to `/?noticias=…#noticias`;
    resources/js/home.js switches them in place and adds the arrow keys.
    Headlines open outside the portal.
--}}
@props(['tabs'])

<section id="noticias" aria-labelledby="noticias-titulo" data-news {{ $attributes->merge(['class' => 'flex min-w-0 scroll-mt-6 flex-col gap-3 md:gap-3.5']) }}>
    <h2 id="noticias-titulo" class="font-sans text-sm font-bold tracking-normal text-brand-text">Noticias</h2>
    <div role="tablist" aria-labelledby="noticias-titulo" class="flex flex-wrap gap-2" data-news-tabs>
        @foreach ($tabs as $tab)
            <a role="tab" id="noticias-tab-{{ $tab->key }}" href="{{ $tab->href() }}" aria-selected="{{ $tab->selected ? 'true' : 'false' }}" aria-controls="noticias-{{ $tab->key }}" data-news-tab="{{ $tab->key }}" class="inline-flex min-h-touch items-center rounded-full border border-line-strong px-4 text-sm font-semibold hover:bg-subtle aria-selected:border-brand-ink aria-selected:bg-brand-tint aria-selected:text-brand-ink">{{ $tab->label }}</a>
        @endforeach
    </div>
    @foreach ($tabs as $tab)
        <div role="tabpanel" id="noticias-{{ $tab->key }}" aria-labelledby="noticias-tab-{{ $tab->key }}" data-news-panel="{{ $tab->key }}" class="rounded-card border border-line bg-surface px-4 md:px-5" @if (! $tab->selected) hidden @endif>
            <ul class="flex flex-col divide-y divide-line">
                @foreach ($tab->headlines as $headline)
                    <li class="flex flex-col gap-0.5 py-3">
                        @if ($href = \App\View\Home\WebLink::href($headline->link))
                            <a href="{{ $href }}" target="_blank" rel="noopener noreferrer" class="text-[0.9375rem] leading-snug font-semibold break-words underline-offset-4 hover:underline">{{ $headline->title }}<span class="sr-only"> (se abre en otra pestaña)</span></a>
                        @else
                            <p class="text-[0.9375rem] leading-snug font-semibold break-words">{{ $headline->title }}</p>
                        @endif
                        <p class="text-xs text-fg-muted">{{ $headline->source }} · <time datetime="{{ $headline->publishedAt->toIso8601String() }}">{{ \App\View\Home\NewsTab::dateOf($headline) }}</time></p>
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
</section>
