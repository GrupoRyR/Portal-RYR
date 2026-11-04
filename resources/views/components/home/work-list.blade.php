{{--
    «Tareas pendientes», the first block of the «Mi día» card (glass.css
    section 7; odd/tasks/inicio-bloques.md H3,
    docs/contrato-tareas-aprobaciones.md §4 to §7; App\View\Home\WorkList):
    «Por aprobar» first, then «Tareas». «Ver tarea» and «Ver aprobación»
    open the app in a new tab. «Completar» (only a task the portal may
    complete) is a <details>: it opens the confirmation with the POST form,
    so it asks first with or without JavaScript; home.js only closes the
    other open ones and blocks a second press while the app answers.
    WorkItemCompletionController comes back to #tareas with its message
    ($status, flashed as `work_status`), shown here. It carries data-flash so
    the service worker (resources/js/sw.js, FLASH) leaves it out of the saved copy.
--}}
@props(['work' => null, 'status' => null])

<section id="tareas" aria-labelledby="tareas-titulo" {{ $attributes->merge(['class' => 'flex scroll-mt-6 flex-col gap-3']) }}>
    <h3 id="tareas-titulo">Tareas pendientes</h3>

    @if ($status)
        <p role="status" data-flash data-work-status class="rounded-control border border-line bg-ground px-3 py-2 text-sm font-semibold">{{ $status }}</p>
    @endif

    @if ($work === null)
        <p class="text-sm text-fg-muted">No tienes tareas ni aprobaciones pendientes.</p>
    @else
        @foreach ([
            'approval' => ['Por aprobar', $work->approvals, $work->moreApprovals],
            'task' => ['Tareas', $work->tasks, $work->moreTasks],
        ] as $kind => [$heading, $rows, $more])
            @continue($rows === [])
            <div data-work-kind="{{ $kind }}" class="flex flex-col gap-2">
                <h4 class="g-label">{{ $heading }}</h4>
                <ul class="flex flex-col divide-y divide-line">
                    @foreach ($rows as $row)
                        <li data-work-item="{{ $row->id }}" class="flex flex-col gap-2 py-3 first:pt-1 last:pb-0">
                            <div class="flex min-w-0 flex-col gap-1">
                                <p class="text-sm leading-snug font-bold break-words">{{ $row->title }}</p>
                                @if ($row->appName || $row->dueLabel)
                                    <p class="flex flex-wrap items-center gap-1.5 text-xs">
                                        @if ($row->appName)
                                            <span class="rounded-full bg-subtle px-2 py-0.5 font-semibold text-fg-soft">{{ $row->appName }}</span>
                                        @endif
                                        @if ($row->dueLabel)
                                            <time datetime="{{ $row->dueIso }}" @class([
                                                'rounded-full px-2 py-0.5 font-semibold',
                                                'bg-status-failure-bg text-status-failure' => $row->overdue,
                                                'bg-status-neutral-bg text-status-neutral' => ! $row->overdue,
                                            ])>{{ $row->dueLabel }}</time>
                                        @endif
                                    </p>
                                @endif
                            </div>
                            @if ($row->url || $row->completeAction)
                                <div class="flex flex-wrap items-start gap-2">
                                    @if ($row->url)
                                        <a href="{{ $row->url }}" target="_blank" rel="noopener noreferrer" class="g-btn px-3 text-[0.8125rem]">{{ $row->viewLabel() }}<span class="sr-only">: {{ $row->title }} (se abre en otra pestaña)</span></a>
                                    @endif
                                    @if ($row->completeAction)
                                        <details data-complete class="open:basis-full">
                                            <summary class="g-btn g-btn--primary list-none px-3 text-[0.8125rem] [&::-webkit-details-marker]:hidden">Completar<span class="sr-only">: {{ $row->title }}</span></summary>
                                            <form method="POST" action="{{ $row->completeAction }}" data-complete-form class="mt-2 flex flex-col gap-2 rounded-control border border-line bg-ground p-3">
                                                @csrf
                                                <p class="text-[0.8125rem]">¿Marcar «{{ $row->title }}» como completada{{ $row->appName ? ' en '.$row->appName : '' }}?</p>
                                                <button type="submit" class="g-btn g-btn--primary self-start px-4 text-[0.8125rem] disabled:cursor-wait disabled:opacity-70">Sí, completar</button>
                                            </form>
                                        </details>
                                    @endif
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @foreach ($more as $link)
                    <a href="{{ $link['href'] }}" target="_blank" rel="noopener noreferrer" data-work-more="{{ $kind }}" class="g-more ml-0 self-start">Ver todas en {{ $link['app'] }}<span class="sr-only"> (se abre en otra pestaña)</span></a>
                @endforeach
            </div>
        @endforeach
    @endif
</section>
