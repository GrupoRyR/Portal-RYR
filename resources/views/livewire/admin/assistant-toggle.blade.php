{{-- «Asistente del portal» in Configuración (App\Livewire\Admin\AssistantToggle): the runtime switch of /asistente. --}}
@php
    $card = 'flex min-w-0 flex-col gap-3.5 rounded-[18px] border border-line bg-surface p-5 sm:p-[22px]';
    $hint = 'text-[0.8125rem] text-fg-muted';
@endphp
<section aria-labelledby="s-assistant" class="{{ $card }}">
    <h2 id="s-assistant" class="font-sans text-lg font-bold tracking-normal">Asistente del portal</h2>

    <p role="status" @class(['rounded-control bg-status-active-bg px-4 py-3 text-sm font-semibold text-status-active', 'sr-only' => $notice === ''])>{{ $notice }}</p>

    <label class="flex min-h-touch cursor-pointer items-center justify-between gap-4">
        <span class="flex flex-col gap-0.5">
            <span class="text-[0.9375rem] font-semibold">Mostrar el asistente a las personas</span>
            <span id="s-assistant-h" class="{{ $hint }}">
                {{ $enabled ? 'Encendido: cada persona puede preguntarle por lo que ya ve en el portal.' : 'Apagado: /asistente responde 404 y no aparece en el portal.' }}
                Se aplica al instante, sin «Guardar cambios».
            </span>
        </span>
        <input type="checkbox" role="switch" wire:click="toggle" wire:loading.attr="disabled" wire:target="toggle" @checked($enabled) aria-describedby="s-assistant-h"
               class="relative h-[26px] w-11 shrink-0 cursor-pointer appearance-none rounded-full bg-line-strong transition-colors before:absolute before:top-[3px] before:left-[3px] before:size-5 before:rounded-full before:bg-white before:transition-[left] checked:bg-status-active-dot checked:before:left-[21px]">
    </label>

    <dl class="grid gap-x-4 gap-y-1 text-sm">
        <dt class="pt-1 font-semibold">Personas que preguntan</dt>
        <dd class="break-all">{{ $settings->model }}</dd>
        <dt class="pt-1 font-semibold">Editores de anuncios</dt>
        <dd class="break-all">{{ $settings->editorModel }}</dd>
        <dt class="pt-1 font-semibold">Respaldo (solo si el modelo no quiere responder)</dt>
        <dd class="break-all">{{ $settings->fallbackModel ?? 'Ninguno' }}</dd>
        <dt class="pt-1 font-semibold">Gasto de este mes</dt>
        <dd>US$ {{ number_format($spent, 2, ',', '.') }} de US$ {{ number_format($budget, 2, ',', '.') }} ({{ number_format($percent, 0, ',', '.') }} %)</dd>
    </dl>

    <p class="{{ $hint }}">
        Hasta {{ $settings->dailyQuestions }} preguntas por persona al día y {{ $settings->conversationTurns }} por conversación. Solo lee lo que cada cuenta ya ve; cada pregunta queda en Auditoría con sus tokens y su costo, sin su texto. El presupuesto se cambia en el servidor (ASSISTANT_MONTHLY_BUDGET_USD).
    </p>

    @if ($percent >= 100)
        <p class="rounded-control bg-status-failure-bg px-4 py-3 text-sm font-semibold text-status-failure">Se alcanzó el presupuesto del mes: el asistente no responde hasta el 1.º del próximo mes.</p>
    @endif

    @unless ($deployed)
        <p class="rounded-control bg-status-failure-bg px-4 py-3 text-sm font-semibold text-status-failure">PORTAL_ASSISTANT_ENABLED está apagado en el servidor: el asistente sigue apagado aunque este interruptor esté encendido.</p>
    @endunless

    @unless ($configured)
        <p class="rounded-control bg-status-failure-bg px-4 py-3 text-sm font-semibold text-status-failure">Falta ANTHROPIC_API_KEY en el servidor: la página dirá que el asistente no está disponible.</p>
    @endunless
</section>
