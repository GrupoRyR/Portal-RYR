{{-- Integraciones (docs/diseno/AdminIntegrations.dc.html): external systems, detail panel and the one-time «Token creado» dialog (AdminTokenNew.dc.html). --}}
@php
    use App\Models\Integration;

    $field = 'h-11 w-full min-w-0 rounded-[10px] border border-line-strong bg-surface px-3 text-[0.9375rem] text-fg placeholder:text-fg-muted';
    $label = 'text-[0.8125rem] font-semibold';
    $hint = 'text-[0.8125rem] text-fg-muted';
    $error = 'text-[0.8125rem] font-semibold text-status-failure';
    $card = 'flex min-w-0 flex-col gap-[18px] rounded-[18px] border border-line bg-surface p-5 sm:p-[22px]';
    $code = 'rounded-md bg-subtle px-[7px] py-[3px] font-mono text-xs';
    $secondary = 'inline-flex h-11 items-center justify-center gap-2 rounded-[10px] border border-line-strong bg-surface px-4 text-sm font-semibold hover:bg-subtle';
    $statusColor = ['operational' => 'text-status-active', 'degraded' => 'text-status-maintenance', 'down' => 'text-status-failure'];
@endphp
<div class="flex flex-col gap-6">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex min-w-0 max-w-[62ch] flex-col gap-1">
            <h1 class="text-[1.75rem] leading-tight font-bold sm:text-[2rem]">Integraciones</h1>
            <p class="text-[0.9375rem] text-fg-muted">Tokens para que otros sistemas lean el catálogo o reporten el estado de las apps. Si nadie los usa, el portal funciona igual.</p>
        </div>
        <button type="button" wire:click="startCreating"
                class="inline-flex h-11 shrink-0 items-center gap-2 rounded-control bg-brand-button px-[18px] text-[0.9375rem] font-semibold text-white max-sm:w-full max-sm:justify-center">
            <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            Nuevo token
        </button>
    </header>

    <p role="status" @class(['rounded-control bg-status-active-bg px-4 py-3 text-sm font-semibold text-status-active', 'sr-only' => $notice === ''])>{{ $notice }}</p>

    <h2 class="border-b border-line">
        <span class="-mb-px inline-flex h-11 items-center border-b-2 border-fg px-3.5 font-sans text-[0.9375rem] font-bold tracking-normal">
            Sistemas externos <span class="ml-1 font-semibold text-fg-muted">· {{ $integrations->count() }}</span>
        </span>
    </h2>

    <div class="flex flex-col gap-6 min-[1200px]:flex-row min-[1200px]:items-start">
        <div class="min-w-0 flex-1 overflow-hidden rounded-[18px] border border-line bg-surface">
            @if ($integrations->isEmpty())
                <p class="px-5 py-12 text-center text-[0.9375rem] text-fg-muted">Aún no hay sistemas externos. Crea un token para que un monitor o una herramienta de TI lea el catálogo o reporte el estado de las apps.</p>
            @else
                <table class="w-full border-collapse text-sm max-md:block">
                    <thead class="max-md:sr-only">
                        <tr class="border-b border-line text-left text-xs font-bold tracking-[0.06em] text-fg-muted uppercase">
                            <th scope="col" class="px-4 py-3">Sistema</th>
                            <th scope="col" class="px-4 py-3">Permisos</th>
                            <th scope="col" class="px-4 py-3">Último uso</th>
                            <th scope="col" class="px-4 py-3">Estado</th>
                        </tr>
                    </thead>
                    <tbody class="max-md:block">
                        @foreach ($integrations as $integration)
                            @php($current = $selected?->is($integration))
                            @php($revoked = $integration->isRevoked())
                            <tr wire:key="integration-{{ $integration->id }}"
                                @class([
                                    'border-b border-line align-middle last:border-b-0 max-md:flex max-md:flex-wrap max-md:items-center max-md:gap-x-3 max-md:gap-y-1.5 max-md:px-4 max-md:py-3',
                                    'bg-ground shadow-[inset_3px_0_0_var(--color-fg)]' => $current,
                                    'text-fg-muted' => $revoked,
                                ])>
                                <td class="px-4 py-3.5 max-md:basis-full max-md:p-0">
                                    <a href="{{ route('admin.integrations.index', ['sistema' => $integration->id]) }}"
                                       wire:click.prevent="select({{ $integration->id }})"
                                       @if ($current) aria-current="true" @endif
                                       class="flex min-h-touch min-w-0 flex-col justify-center gap-0.5">
                                        <span @class(['font-bold break-words', 'line-through' => $revoked])>{{ $integration->name }}</span>
                                        @if ($integration->maskedToken())
                                            <span class="font-mono text-xs text-fg-muted">{{ $integration->maskedToken() }}</span>
                                        @endif
                                    </a>
                                </td>
                                <td class="px-4 py-3.5 max-md:p-0">
                                    <span class="flex flex-wrap gap-1.5">
                                        @foreach ($integration->abilities as $ability)
                                            <code class="{{ $code }}">{{ $ability }}</code>
                                        @endforeach
                                    </span>
                                </td>
                                <td class="px-4 py-3.5 whitespace-nowrap max-md:p-0 max-md:text-xs">
                                    <span class="md:sr-only">Último uso: </span>{{ $integration->last_used_at?->diffForHumans() ?? 'Nunca' }}
                                </td>
                                <td class="px-4 py-3.5 max-md:p-0">
                                    @if ($revoked)
                                        <span class="rounded-lg bg-status-neutral-bg px-2.5 py-1 text-[0.8125rem] font-semibold whitespace-nowrap text-status-neutral">Revocado</span>
                                    @else
                                        <span class="rounded-lg bg-status-active-bg px-2.5 py-1 text-[0.8125rem] font-semibold whitespace-nowrap text-status-active">Activo</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        @if ($creating)
            {{-- New token --}}
            <form wire:submit="create" aria-labelledby="in-new" class="{{ $card }} min-[1200px]:w-[400px] min-[1200px]:shrink-0" novalidate>
                <h2 id="in-new" class="font-sans text-lg font-bold tracking-normal">Nuevo token</h2>

                <div class="flex flex-col gap-1.5">
                    <label for="in-name" class="{{ $label }}">Nombre del sistema</label>
                    <input id="in-name" type="text" wire:model="name" maxlength="{{ Integration::MAX_NAME }}" placeholder="Monitor de disponibilidad" class="{{ $field }}"
                           @error('name') aria-invalid="true" aria-describedby="in-name-error" @enderror>
                    @error('name') <p id="in-name-error" class="{{ $error }}">{{ $message }}</p> @enderror
                </div>

                <fieldset class="flex flex-col gap-1">
                    <legend class="mb-1.5 text-[0.8125rem] font-bold">Permisos</legend>
                    @foreach (Integration::ABILITIES as $ability => $description)
                        <label class="flex min-h-touch cursor-pointer items-start gap-3 pt-1">
                            <input type="checkbox" wire:model="abilities" value="{{ $ability }}" class="mt-0.5 size-[18px] shrink-0 accent-fg">
                            <span class="flex flex-col gap-0.5">
                                <code class="text-[0.8125rem] font-bold">{{ $ability }}</code>
                                <span class="{{ $hint }}">{{ $description }}</span>
                            </span>
                        </label>
                    @endforeach
                    @error('abilities') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    @error('abilities.*') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </fieldset>

                <div class="flex flex-col gap-1.5">
                    <label for="in-ips" class="{{ $label }}">IP permitidas <span class="font-normal text-fg-muted">(opcional)</span></label>
                    <input id="in-ips" type="text" wire:model="ips" placeholder="10.0.0.5, 192.168.1.0/24" aria-describedby="in-ips-hint" class="{{ $field }} font-mono"
                           @error('ips') aria-invalid="true" @enderror>
                    <span id="in-ips-hint" class="{{ $hint }}">Sepáralas con comas. Vacío: se acepta desde cualquier IP.</span>
                    @error('ips') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>

                <div class="flex flex-wrap justify-end gap-2.5">
                    <button type="button" wire:click="cancelCreating" class="{{ $secondary }}">Cancelar</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="create"
                            class="inline-flex h-11 items-center rounded-control bg-brand-button px-5 text-sm font-semibold text-white disabled:opacity-60">Crear token</button>
                </div>
            </form>
        @elseif ($selected)
            {{-- Detail --}}
            @php($live = ! $selected->isRevoked())
            <aside aria-labelledby="in-detail" class="{{ $card }} min-[1200px]:w-[400px] min-[1200px]:shrink-0">
                <div class="flex flex-col gap-1">
                    <h2 id="in-detail" class="font-sans text-lg font-bold tracking-normal break-words">{{ $selected->name }}</h2>
                    <span class="text-[0.8125rem] text-fg-muted">{{ 'Creado '.$selected->created_at->timezone(config('app.timezone'))->locale('es')->isoFormat('D MMM YYYY').($selected->creator ? ' por '.$selected->creator->name : '').' · el token completo solo se mostró al crearlo' }}</span>
                    @unless ($live)
                        <span class="text-[0.8125rem] font-semibold text-status-neutral">Revocado {{ $selected->revoked_at->diffForHumans() }}. Ya no funciona.</span>
                    @endunless
                </div>

                <fieldset class="flex flex-col gap-1" @disabled(! $live)>
                    <legend class="mb-1.5 text-[0.8125rem] font-bold">Permisos</legend>
                    @foreach (Integration::ABILITIES as $ability => $description)
                        <label @class(['flex min-h-touch items-start gap-3 pt-1', 'cursor-pointer' => $live])>
                            <input type="checkbox" wire:click="toggleAbility('{{ $ability }}')" @checked(in_array($ability, $selected->abilities, true))
                                   class="mt-0.5 size-[18px] shrink-0 accent-fg">
                            <span class="flex flex-col gap-0.5">
                                <code class="text-[0.8125rem] font-bold">{{ $ability }}</code>
                                <span class="{{ $hint }}">{{ $description }}</span>
                            </span>
                        </label>
                    @endforeach
                    @error('abilities') <p role="alert" class="{{ $error }}">{{ $message }}</p> @enderror
                </fieldset>

                <div class="flex flex-col gap-2">
                    <span id="in-ips-label" class="text-[0.8125rem] font-bold">IP permitidas</span>
                    <div role="group" aria-labelledby="in-ips-label" class="flex flex-wrap gap-2">
                        @forelse ($selected->allowed_ips ?? [] as $ip)
                            <span wire:key="ip-{{ $ip }}" class="inline-flex h-9 max-w-full items-center gap-1 rounded-[10px] bg-subtle pl-3 font-mono text-[0.8125rem] {{ $live ? 'pr-1' : 'pr-3' }}">
                                <span class="truncate">{{ $ip }}</span>
                                @if ($live)
                                    <button type="button" wire:click="removeIp('{{ $ip }}')" wire:confirm="¿Quitar {{ $ip }}? Este sistema ya no podrá llamar desde esa IP."
                                            class="inline-flex size-7 shrink-0 items-center justify-center rounded-md text-fg-muted hover:bg-surface hover:text-fg">
                                        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
                                        <span class="sr-only">Quitar {{ $ip }}</span>
                                    </button>
                                @endif
                            </span>
                        @empty
                            <span class="{{ $hint }}">Cualquier IP.</span>
                        @endforelse
                    </div>
                    @if ($live)
                        <form wire:submit="addIp" class="flex gap-2">
                            <label for="in-new-ip" class="sr-only">Agregar IP</label>
                            <input id="in-new-ip" type="text" wire:model="newIp" placeholder="10.0.0.5 o 192.168.1.0/24" class="{{ $field }} h-9 font-mono text-[0.8125rem]"
                                   @error('newIp') aria-invalid="true" aria-describedby="in-new-ip-error" @enderror>
                            <button type="submit" class="h-9 shrink-0 rounded-[10px] border border-dashed border-line-strong px-3 text-[0.8125rem] font-semibold text-fg-soft hover:bg-subtle">+ Agregar IP</button>
                        </form>
                        @error('newIp') <p id="in-new-ip-error" class="{{ $error }}">{{ $message }}</p> @enderror
                    @endif
                </div>

                <div class="flex flex-col gap-2">
                    <span class="text-[0.8125rem] font-bold">Últimos cambios que hizo</span>
                    @if ($latestChanges->isEmpty())
                        <span class="{{ $hint }}">Aún no ha reportado estados.</span>
                    @else
                        <ol role="list" class="flex flex-col rounded-[12px] border border-line">
                            @foreach ($latestChanges as $event)
                                <li class="flex flex-wrap justify-between gap-x-2 border-b border-line px-3 py-2.5 text-[0.8125rem] last:border-b-0">
                                    <span><code>{{ $event->app?->slug ?? '—' }}</code> → <strong class="{{ $statusColor[$event->status] ?? '' }}">{{ $event->status }}</strong></span>
                                    <time datetime="{{ $event->created_at->toIso8601String() }}" class="text-fg-muted">{{ $event->created_at->diffForHumans() }}</time>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </div>

                @if ($live)
                    <div class="flex gap-2.5 pt-1">
                        <button type="button" wire:click="rotate" wire:confirm="¿Rotar el token de {{ $selected->name }}? El actual deja de funcionar de inmediato y tendrás que poner el nuevo en el sistema."
                                class="{{ $secondary }} flex-1">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 11a8 8 0 0 0-14.9-3.9M4 4v4h4M4 13a8 8 0 0 0 14.9 3.9M20 20v-4h-4"/></svg>
                            Rotar
                        </button>
                        <button type="button" wire:click="revoke" wire:confirm="¿Revocar el token de {{ $selected->name }}? Deja de funcionar de inmediato y no se puede deshacer."
                                class="inline-flex h-11 flex-1 items-center justify-center rounded-[10px] border border-status-failure/40 bg-surface px-4 text-sm font-semibold text-status-failure hover:bg-status-failure-bg">
                            Revocar
                        </button>
                    </div>
                @endif
            </aside>
        @endif
    </div>

    @if ($plainToken !== null && $tokenOwner)
        {{-- «Token creado»: the only time the plaintext is shown. --}}
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 px-4 py-10 sm:py-[110px]">
            <section role="dialog" aria-modal="true" aria-labelledby="tk-title" x-data="{ saved: false, copied: false }" x-init="$nextTick(() => $refs.token.focus())"
                     x-on:keydown.escape.window="saved && $wire.dismissToken()"
                     class="flex w-full max-w-[640px] min-w-0 flex-col gap-5 rounded-[22px] bg-surface p-6 shadow-2xl sm:p-8">
                <div class="flex items-center gap-3.5">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-status-active-bg text-status-active" aria-hidden="true">
                        <svg class="size-[22px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5 9-10"/></svg>
                    </span>
                    <div class="flex min-w-0 flex-col gap-0.5">
                        <h2 id="tk-title" class="font-sans text-[1.375rem] font-bold tracking-normal">{{ $rotated ? 'Token nuevo' : 'Token creado' }}</h2>
                        <span class="text-sm break-words text-fg-muted">{{ $tokenOwner->name }} · {{ implode(', ', $tokenOwner->abilities) }}</span>
                    </div>
                </div>

                <div role="alert" class="flex gap-3 rounded-[12px] bg-status-maintenance-bg px-4 py-3.5 text-sm leading-relaxed text-status-maintenance">
                    <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17.5v.01"/></svg>
                    <span><strong>Cópialo ahora.</strong> Por seguridad no lo volveremos a mostrar. Si se pierde, rótalo y crea uno nuevo.@if ($rotated) El anterior ya no funciona.@endif</span>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label for="tk" class="{{ $label }}">Token</label>
                    <div class="flex gap-2 max-sm:flex-col">
                        <input id="tk" x-ref="token" type="text" readonly value="{{ $plainToken }}" autocomplete="off" spellcheck="false"
                               class="h-12 w-full min-w-0 rounded-[10px] border border-line-strong bg-ground px-3.5 font-mono sm:flex-1 text-sm text-fg">
                        <button type="button" x-on:click="navigator.clipboard.writeText($refs.token.value).then(() => { copied = true; setTimeout(() => copied = false, 2500) })"
                                class="inline-flex h-12 shrink-0 items-center justify-center gap-2 rounded-[10px] bg-fg px-[18px] text-[0.9375rem] font-semibold text-on-fg">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></svg>
                            <span x-text="copied ? 'Copiado' : 'Copiar'">Copiar</span>
                        </button>
                    </div>
                    <p class="sr-only" aria-live="polite" x-text="copied ? 'Token copiado' : ''"></p>
                </div>

                <div class="flex flex-col gap-2">
                    <span class="{{ $label }}">Ejemplo: reportar que una app falla</span>
                    <pre class="overflow-x-auto rounded-[12px] bg-fg p-4 font-mono text-[0.8125rem] leading-relaxed whitespace-pre-wrap text-on-fg">PUT /api/v1/apps/cartera/status
Authorization: Bearer &lt;token&gt;

{ "status": "degraded", "message": "Estamos revisando" }</pre>
                </div>

                <label class="flex min-h-touch cursor-pointer items-center gap-3 text-[0.9375rem] font-semibold">
                    <input type="checkbox" x-model="saved" class="size-[18px] shrink-0 accent-fg">
                    Ya lo guardé en un lugar seguro
                </label>

                <div class="flex justify-end">
                    <button type="button" wire:click="dismissToken" x-bind:disabled="! saved" disabled
                            class="inline-flex h-12 items-center rounded-control bg-fg px-[22px] text-[0.9375rem] font-semibold text-on-fg disabled:cursor-not-allowed disabled:bg-line disabled:text-fg-muted">
                        Listo
                    </button>
                </div>
            </section>
        </div>
    @endif
</div>
