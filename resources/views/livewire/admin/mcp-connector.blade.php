{{-- «Conector de Claude» in Configuración (App\Livewire\Admin\McpConnector): the switch and the active connections. --}}
@php
    $card = 'flex min-w-0 flex-col gap-3.5 rounded-[18px] border border-line bg-surface p-5 sm:p-[22px]';
    $hint = 'text-[0.8125rem] text-fg-muted';
    $longDate = 'D [de] MMMM [de] YYYY, HH:mm';
    $local = fn ($moment) => $moment->timezone(config('app.timezone'))->locale('es')->isoFormat($longDate);
@endphp
<section aria-labelledby="s-mcp" class="{{ $card }}">
    <h2 id="s-mcp" class="font-sans text-lg font-bold tracking-normal">Conector de Claude</h2>

    <p role="status" @class(['rounded-control bg-status-active-bg px-4 py-3 text-sm font-semibold text-status-active', 'sr-only' => $notice === ''])>{{ $notice }}</p>

    <label class="flex min-h-touch cursor-pointer items-center justify-between gap-4">
        <span class="flex flex-col gap-0.5">
            <span class="text-[0.9375rem] font-semibold">Permitir que Claude se conecte al portal</span>
            <span id="s-mcp-h" class="{{ $hint }}">
                {{ $enabled ? 'Encendido: Claude puede conectarse al portal.' : 'Apagado: el conector responde 404 y ninguna conexión funciona.' }}
                Se aplica al instante, sin «Guardar cambios».
            </span>
        </span>
        <input type="checkbox" role="switch" wire:click="toggle" wire:loading.attr="disabled" wire:target="toggle" @checked($enabled) aria-describedby="s-mcp-h"
               class="relative h-[26px] w-11 shrink-0 cursor-pointer appearance-none rounded-full bg-line-strong transition-colors before:absolute before:top-[3px] before:left-[3px] before:size-5 before:rounded-full before:bg-white before:transition-[left] checked:bg-status-active-dot checked:before:left-[21px]">
    </label>

    @unless ($deployed)
        <p class="rounded-control bg-status-failure-bg px-4 py-3 text-sm font-semibold text-status-failure">PORTAL_MCP_ENABLED está apagado en el servidor: el conector sigue apagado aunque este interruptor esté encendido.</p>
    @endunless

    <h3 class="pt-2 text-[0.9375rem] font-bold">Conexiones activas</h3>
    @if ($connections->isEmpty())
        <p class="{{ $hint }}">No hay conexiones activas.</p>
    @else
        @if ($total > $connections->count())
            <p class="{{ $hint }}">Se muestran las {{ $connections->count() }} más recientes de {{ $total }}.</p>
        @endif
        <ul role="list" class="flex flex-col divide-y divide-line rounded-control border border-line">
            @foreach ($connections as $connection)
                <li wire:key="mcp-{{ $connection->id }}" class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 px-4 py-3">
                    <span class="flex min-w-0 flex-col gap-1 text-sm">
                        <span class="font-semibold">{{ $connection->client?->name ?? 'Cliente eliminado' }}</span>
                        @foreach (\App\Mcp\Connections::accountsOf($connection) as $account)
                            <span class="min-w-0 truncate">
                                {{ $account->name }} · {{ $account->email }} · {{ \App\Models\Company::displayName($account->company) }}{{ $account->id === $connection->user_id ? ' (aprobó)' : '' }}
                            </span>
                        @endforeach
                        <span class="{{ $hint }}">Conectada el {{ $local($connection->created_at) }} · {{ $connection->last_used_at ? 'último uso: '.$local($connection->last_used_at) : 'aún no se ha usado' }}</span>
                    </span>
                    <button type="button" wire:click="revoke({{ $connection->id }})" wire:confirm="¿Revocar esta conexión? Claude dejará de leer los datos de sus cuentas."
                            class="inline-flex h-10 shrink-0 items-center rounded-[10px] border border-line-strong bg-surface px-3.5 text-sm font-semibold text-status-failure hover:bg-subtle">
                        Revocar<span class="sr-only"> la conexión de {{ $connection->user?->name }}</span>
                    </button>
                </li>
            @endforeach
        </ul>
    @endif
</section>
