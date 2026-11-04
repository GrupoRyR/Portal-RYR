{{--
    «Asistente» (App\Http\Controllers\Assistant\AssistantController): the
    conversation of the signed-in account, kept in its session only. Every
    action is a plain form POST with a redirect back: no Livewire, no Alpine,
    no JavaScript needed. Answers are escaped text; only links to the portal
    are clickable (App\View\Assistant\AnswerText). An announcements editor
    may get a draft (App\Assistant\Tools\BorradorDeAnuncio), which only the
    form below, sent by them with the confirmation box, publishes.
--}}
@use('App\Http\Controllers\Assistant\AssistantController')
@use('App\Models\Announcement')
@use('App\View\Assistant\AnswerText')
@php
    $card = 'flex flex-col gap-4 rounded-card border border-line bg-surface px-5 py-5 md:px-6';
    $field = 'w-full rounded-control border border-line-strong bg-surface px-3.5 py-3 text-base';
    $button = 'inline-flex min-h-touch shrink-0 cursor-pointer items-center justify-center gap-2 rounded-control bg-brand-button px-5 text-[0.9375rem] font-semibold text-white';
    $secondary = 'inline-flex min-h-touch shrink-0 cursor-pointer items-center justify-center rounded-control border border-line-strong bg-surface px-4 text-sm font-semibold hover:bg-subtle';
    $error = 'text-sm font-semibold text-status-failure';
    $suggestions = [
        '¿Qué tareas y aprobaciones tengo pendientes?',
        '¿Hay anuncios que deba leer?',
        '¿Cuáles son los próximos festivos y eventos?',
        '¿Quién cumple años esta semana?',
        '¿Está fallando algo de TI?',
    ];
    $last = array_key_last($turns);
    // No question can be sent: the day's cap, a full conversation or the month's budget.
    $closed = $remaining === 0 || $full || $budgetReached;
@endphp
<x-layouts.app title="Asistente" brand-band>
    <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-control focus:bg-surface focus:px-4 focus:py-3 focus:font-semibold">Saltar al contenido</a>

    <x-brand-bar />

    <main id="contenido" class="mx-auto flex max-w-[760px] flex-col gap-6 px-4 pt-4 pb-12 md:px-8 md:pt-8">
        <div class="flex flex-col gap-2">
            <a href="{{ route('home') }}" class="-ml-1 inline-flex min-h-touch items-center gap-1.5 self-start pr-2 text-sm font-semibold text-fg-soft underline-offset-4 hover:underline">
                <x-home.icon name="chevron-left" stroke-width="2" class="size-[18px]" />Volver al inicio
            </a>
            <h1 class="text-[1.75rem] leading-tight font-bold md:text-[2.125rem]">Asistente</h1>
            <p class="text-[0.9375rem] text-fg-soft">Pregúntale por lo que ya ves en el portal{{ $company !== '' ? ' en '.$company : '' }}: tus apps, anuncios, fechas, cumpleaños, personas, tareas y el estado de TI.</p>
        </div>

        @if (session('status'))
            <p role="status" class="rounded-control bg-status-active-bg px-4 py-3 text-sm font-semibold text-status-active">
                {{ session('status') }}
                @if (session('published'))
                    <a href="{{ route('announcements.show', session('published')) }}" class="underline underline-offset-4">Ver el anuncio</a>
                @endif
            </p>
        @endif

        @if ($restarted)
            <p role="status" class="rounded-control bg-status-active-bg px-4 py-3 text-sm font-semibold text-status-active">Empezamos una conversación nueva porque cambió tu cuenta o la configuración del asistente.</p>
        @endif

        @if ($secondary && $available)
            <p role="status" class="rounded-control border border-line bg-surface px-4 py-3 text-sm font-semibold text-fg-soft">Respondiendo con el proveedor secundario.</p>
        @endif

        @unless ($available)
            <p class="rounded-card border border-line bg-surface px-5 py-8 text-center text-[0.9375rem] text-fg-muted">{{ AssistantController::NOT_AVAILABLE }}</p>
        @else
            @if ($turns === [])
                <section aria-labelledby="sugerencias" class="{{ $card }}">
                    <h2 id="sugerencias" class="font-sans text-base font-bold tracking-normal">Puedes empezar con</h2>
                    <ul role="list" class="flex flex-wrap gap-2">
                        @foreach ($suggestions as $suggestion)
                            <li>
                                <form method="POST" action="{{ route('assistant.ask') }}">
                                    @csrf
                                    <input type="hidden" name="pregunta" value="{{ $suggestion }}">
                                    <button type="submit" @disabled($closed) class="inline-flex min-h-touch cursor-pointer items-center rounded-full border border-line-strong bg-surface px-4 text-left text-sm font-semibold hover:bg-subtle disabled:cursor-not-allowed disabled:opacity-60">{{ $suggestion }}</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @else
                <section aria-labelledby="conversacion">
                    <h2 id="conversacion" class="sr-only">Conversación</h2>
                    <ol role="list" class="flex flex-col gap-4">
                        @foreach ($turns as $index => $turn)
                            <li class="flex flex-col gap-3">
                                <div class="flex flex-col gap-1 self-end rounded-card bg-brand-tint px-4 py-3 text-brand-ink max-w-[592px]">
                                    <span class="text-xs font-semibold">Tú</span>
                                    <p class="text-[0.9375rem] break-words whitespace-pre-line">{{ $turn['question'] }}</p>
                                </div>
                                <div @if ($index === $last) id="respuesta" tabindex="-1" @endif class="flex scroll-mt-6 flex-col gap-1 rounded-card border border-line bg-surface px-4 py-3 max-w-[592px]">
                                    <span class="text-xs font-semibold text-fg-muted">Asistente</span>
                                    <p class="text-[0.9375rem] leading-relaxed break-words">{{ AnswerText::html($turn['answer']) }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </section>
            @endif

            @if ($draft)
                <section aria-labelledby="borrador" class="{{ $card }} border-brand-accent">
                    <div class="flex flex-col gap-1">
                        <h2 id="borrador" class="font-sans text-lg font-bold tracking-normal">Borrador de anuncio</h2>
                        <p class="text-sm text-fg-soft">Revísalo y corrígelo antes de publicarlo. Se publica para {{ $company }} desde ahora. Nada se publica hasta que tú lo confirmes aquí.</p>
                    </div>
                    <form method="POST" action="{{ route('assistant.draft.publish') }}" class="flex flex-col gap-4">
                        @csrf
                        <div class="flex flex-col gap-1.5">
                            <label for="borrador-titulo" class="text-sm font-semibold">Título</label>
                            <input id="borrador-titulo" name="title" type="text" required maxlength="{{ Announcement::TITLE_MAX }}" value="{{ old('title', $draft->title) }}" @error('title') aria-invalid="true" aria-describedby="borrador-titulo-error" @enderror class="{{ $field }}">
                            @error('title') <p id="borrador-titulo-error" class="{{ $error }}">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label for="borrador-texto" class="text-sm font-semibold">Texto</label>
                            <textarea id="borrador-texto" name="body" rows="4" required maxlength="{{ Announcement::BODY_MAX }}" @error('body') aria-invalid="true" aria-describedby="borrador-texto-error" @enderror class="{{ $field }}">{{ old('body', $draft->body) }}</textarea>
                            @error('body') <p id="borrador-texto-error" class="{{ $error }}">{{ $message }}</p> @enderror
                        </div>
                        <label class="flex min-h-touch cursor-pointer items-center gap-3 text-sm font-semibold">
                            <input type="checkbox" name="requires_read" value="1" @checked(old('requires_read', $draft->requiresRead)) class="size-5 shrink-0">
                            Lectura obligatoria: cada persona confirma que lo leyó
                        </label>
                        <label class="flex min-h-touch cursor-pointer items-start gap-3 text-sm font-semibold">
                            <input type="checkbox" name="confirm" value="1" required @error('confirm') aria-invalid="true" aria-describedby="borrador-confirmar-error" @enderror class="mt-0.5 size-5 shrink-0">
                            Revisé el anuncio y quiero publicarlo ahora para {{ $company }}.
                        </label>
                        @error('confirm') <p id="borrador-confirmar-error" class="{{ $error }}">{{ $message }}</p> @enderror
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="{{ $button }}">Publicar</button>
                        </div>
                    </form>
                    <form method="POST" action="{{ route('assistant.draft.discard') }}">
                        @csrf
                        <button type="submit" class="{{ $secondary }}">Descartar borrador</button>
                    </form>
                </section>
            @endif

            @if ($budgetReached)
                <p class="rounded-control bg-status-failure-bg px-4 py-3 text-sm font-semibold text-status-failure">El asistente alcanzó el límite de uso de este mes. Vuelve a estar disponible el 1.º del próximo mes.</p>
            @elseif ($full)
                <p class="rounded-control bg-status-failure-bg px-4 py-3 text-sm font-semibold text-status-failure">Esta conversación llegó a su límite de {{ $conversationTurns }} preguntas. Empieza una «Nueva conversación» para seguir preguntando.</p>
            @endif

            <form method="POST" action="{{ route('assistant.ask') }}" class="flex flex-col gap-2">
                @csrf
                <label for="pregunta" class="text-sm font-semibold">Tu pregunta</label>
                <textarea id="pregunta" name="pregunta" rows="3" required maxlength="{{ $maxLength }}" @disabled($closed)
                          aria-describedby="pregunta-ayuda @error('pregunta') pregunta-error @enderror" @error('pregunta') aria-invalid="true" @enderror
                          class="{{ $field }}">{{ old('pregunta') }}</textarea>
                @error('pregunta') <p id="pregunta-error" role="alert" class="{{ $error }}">{{ $message }}</p> @enderror
                <p id="pregunta-ayuda" class="text-[0.8125rem] text-fg-muted">
                    {{ $remaining === 1 ? 'Te queda 1 pregunta' : "Te quedan {$remaining} preguntas" }} de {{ $dailyQuestions }} por hoy. La respuesta puede tardar unos segundos.
                </p>
                <div class="flex flex-wrap gap-2">
                    <button type="submit" @disabled($closed) class="{{ $button }} disabled:cursor-not-allowed disabled:opacity-60">Enviar</button>
                </div>
            </form>

            @if ($turns !== [] || $draft)
                <form method="POST" action="{{ route('assistant.clear') }}">
                    @csrf
                    <button type="submit" class="{{ $secondary }}">Nueva conversación</button>
                </form>
            @endif

            <p class="text-[0.8125rem] text-fg-muted">
                El asistente solo lee lo que tu cuenta ya ve en el portal y no puede cambiar nada. Las respuestas las genera Claude, de Anthropic, y pueden tener errores: revisa los datos importantes en el portal.
                La conversación no se guarda: se borra al cerrar sesión o cambiar de empresa.
            </p>
        @endunless
    </main>
</x-layouts.app>
