{{-- New-company wizard steps (docs/diseno/AdminWizard.dc.html). --}}
@php
    $field = 'h-11 w-full rounded-[10px] border border-line-strong bg-surface px-3 text-[0.9375rem] text-fg';
    $label = 'text-[0.8125rem] font-semibold';
    $error = 'text-[0.8125rem] font-semibold text-status-failure';
    $card = 'flex flex-col gap-[18px] rounded-card border border-line bg-surface p-5 sm:p-6';
    $primaryButton = 'inline-flex min-h-touch items-center justify-center rounded-control bg-brand-button px-5 font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50';
    $secondaryButton = 'inline-flex min-h-touch items-center justify-center rounded-control border border-line-strong bg-surface px-4 text-sm font-semibold hover:bg-subtle';
    $steps = [1 => 'Datos y marca', 2 => 'Consentimiento', 3 => 'Grupos APP-…', 4 => 'Activar'];
    $summaryName = $current?->name ?? (trim($name) ?: 'Empresa nueva');
    $summaryTenant = $current?->tenant_id ?? ($tenantId ?: '—');
    $summaryDomains = $current?->domains ?? $domains;
    $verified = $current?->consent_verified_at !== null;
@endphp
<div class="flex flex-col gap-6">
    <ol aria-label="Pasos" class="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($steps as $number => $title)
            @php($done = $number < $step || ($number === 1 && $current !== null))
            <li @if ($number === $step) aria-current="step" @endif
                @class([
                    'flex items-center gap-2.5 rounded-control px-3.5 py-3',
                    'border-2 border-brand-accent bg-surface' => $number === $step,
                    'border border-line bg-surface' => $done && $number !== $step,
                    'border border-dashed border-line-strong' => ! $done && $number !== $step,
                ])>
                <span @class([
                    'flex size-7 shrink-0 items-center justify-center rounded-full text-[0.8125rem] font-bold',
                    'bg-status-active-dot text-white' => $done && $number !== $step,
                    'bg-brand-button text-white' => $number === $step,
                    'bg-subtle text-fg-muted' => ! $done && $number !== $step,
                ])>
                    @if ($done && $number !== $step)
                        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5 9-10"/></svg>
                    @else
                        {{ $number }}
                    @endif
                </span>
                <span class="flex flex-col leading-tight">
                    <span class="text-xs text-fg-muted">Paso {{ $number }}{{ $done && $number !== $step ? ' · listo' : ($number === $step ? ' · en curso' : '') }}</span>
                    <span class="text-sm font-bold">{{ $title }}</span>
                </span>
            </li>
        @endforeach
    </ol>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px] lg:items-start">
        <div class="flex min-w-0 flex-col gap-5">
            @if ($step === 1)
                <form wire:submit="saveCompany" class="flex flex-col gap-5" novalidate>
                    <section class="{{ $card }}" aria-labelledby="w-data">
                        <div class="flex flex-col gap-1">
                            <h2 id="w-data" class="font-sans text-lg font-bold tracking-normal">Datos de la empresa</h2>
                            <p class="text-sm text-fg-muted">La empresa queda inactiva hasta el paso 4: nadie de esa organización puede entrar todavía.</p>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="flex flex-col gap-1.5">
                                <label for="w-name" class="{{ $label }}">Nombre</label>
                                <input id="w-name" type="text" wire:model.live.debounce.400ms="name" class="{{ $field }}" autocomplete="organization">
                                @error('name') <p class="{{ $error }}">{{ $message }}</p> @enderror
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label for="w-short" class="{{ $label }}">Nombre corto</label>
                                <input id="w-short" type="text" wire:model="shortName" class="{{ $field }}">
                                @error('shortName') <p class="{{ $error }}">{{ $message }}</p> @enderror
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label for="w-slug" class="{{ $label }}">Identificador</label>
                                <input id="w-slug" type="text" wire:model.blur="slug" spellcheck="false" autocapitalize="none" class="{{ $field }} font-mono text-sm" aria-describedby="w-slug-help">
                                <p id="w-slug-help" class="text-[0.8125rem] text-fg-muted">Se usa en las direcciones del portal. No se puede cambiar después.</p>
                                @error('slug') <p class="{{ $error }}">{{ $message }}</p> @enderror
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label for="w-tenant" class="{{ $label }}">ID de inquilino</label>
                                <input id="w-tenant" type="text" wire:model.live.blur="tenantId" spellcheck="false" autocapitalize="none" placeholder="00000000-0000-0000-0000-000000000000" class="{{ $field }} font-mono text-sm" aria-describedby="w-tenant-help">
                                <p id="w-tenant-help" class="text-[0.8125rem] text-fg-muted">Está en Microsoft Entra › Información general › ID de inquilino.</p>
                                @error('tenantId') <p class="{{ $error }}">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        @if ($showPrimaryChoice)
                            <div class="flex flex-col gap-1.5 rounded-control bg-subtle p-4">
                                <label class="flex min-h-touch cursor-pointer items-center gap-3 text-sm font-semibold">
                                    <input type="checkbox" wire:model="isPrimary" class="size-5 accent-fg">
                                    Empresa principal de este inquilino
                                </label>
                                <p class="text-[0.8125rem] text-fg-soft">Este inquilino ya lo usa otra empresa. Sus usuarios se reparten por el dominio del correo; los que no coincidan con ninguno entran a la empresa principal, y solo puede haber una.</p>
                                @error('isPrimary') <p class="{{ $error }}">{{ $message }}</p> @enderror
                            </div>
                        @endif

                        <div class="flex flex-col gap-2">
                            <span id="w-dom" class="{{ $label }}">Dominios de correo</span>
                            @if ($domains !== [])
                                <ul role="list" aria-labelledby="w-dom" class="flex flex-wrap gap-2">
                                    @foreach ($domains as $index => $domain)
                                        <li wire:key="w-domain-{{ $domain }}" class="inline-flex h-9 items-center gap-1 rounded-[10px] bg-subtle pr-1 pl-3 text-sm font-semibold">
                                            {{ $domain }}
                                            <button type="button" wire:click="removeDomain({{ $index }})" aria-label="Quitar {{ $domain }}" class="inline-flex size-8 items-center justify-center rounded-lg text-fg-soft hover:bg-line">
                                                <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            @error('domains') <p class="{{ $error }}">{{ $message }}</p> @enderror
                            @foreach ($domains as $index => $domain)
                                @error('domains.'.$index) <p class="{{ $error }}">{{ $domain }}: {{ $message }}</p> @enderror
                            @endforeach
                            <div class="flex flex-wrap gap-2">
                                <label for="w-new-domain" class="sr-only">Nuevo dominio</label>
                                <input id="w-new-domain" type="text" inputmode="url" autocapitalize="none" spellcheck="false" placeholder="empresa.com"
                                       wire:model="newDomain" wire:keydown.enter.prevent="addDomain" class="{{ $field }} max-w-64 flex-1">
                                <button type="button" wire:click="addDomain" class="inline-flex min-h-touch items-center rounded-[10px] border border-dashed border-line-strong px-3.5 text-sm font-semibold text-fg-soft hover:bg-subtle">+ Agregar dominio</button>
                            </div>
                            @error('newDomain') <p class="{{ $error }}">{{ $message }}</p> @enderror
                        </div>
                    </section>

                    <section class="{{ $card }}" aria-labelledby="w-brand">
                        <div class="flex flex-col gap-1">
                            <h2 id="w-brand" class="font-sans text-lg font-bold tracking-normal">Marca</h2>
                            <p class="text-sm text-fg-muted">Los logos se suben después, desde la ficha de la empresa.</p>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-3">
                            @foreach (['colorPrimary' => 'Principal', 'colorAccent' => 'Acento', 'colorDark' => 'Oscuro'] as $property => $text)
                                @php($valid = preg_match(\App\Livewire\Admin\CompanyEditor::HEX_PATTERN, $this->{$property}) === 1)
                                <div class="flex flex-col gap-1.5">
                                    <label for="w-{{ $property }}" class="{{ $label }}">{{ $text }}</label>
                                    <div class="flex items-center gap-2 rounded-[10px] border border-line-strong bg-surface pr-3 pl-1.5">
                                        <input type="color" wire:model.live="{{ $property }}" value="{{ $valid ? strtolower($this->{$property}) : '#000000' }}" aria-label="Elegir color {{ mb_strtolower($text) }}" class="size-8 shrink-0 cursor-pointer rounded-lg border-0 bg-transparent p-0">
                                        <input id="w-{{ $property }}" type="text" wire:model.live.debounce.400ms="{{ $property }}" maxlength="7" spellcheck="false" class="h-11 min-w-0 flex-1 bg-transparent font-mono text-sm uppercase focus-visible:outline-none">
                                    </div>
                                    @error($property) <p class="{{ $error }}">{{ $message }}</p> @enderror
                                </div>
                            @endforeach
                        </div>
                        @if ($palette->button !== $palette->primary)
                            <p class="rounded-control bg-status-maintenance-bg px-4 py-3 text-sm text-status-maintenance">Con texto blanco el principal no alcanza el contraste; para los botones usaremos <strong class="font-mono">{{ $palette->button }}</strong>.</p>
                        @endif
                    </section>

                    <div class="flex flex-wrap items-center justify-end gap-3">
                        <p class="mr-auto text-[0.8125rem] text-fg-muted">Si pasaron más de 10 minutos desde que confirmaste con Microsoft, te lo pediremos de nuevo.</p>
                        <a href="{{ route('admin.companies.index') }}" class="{{ $secondaryButton }}">Cancelar</a>
                        <button type="submit" class="{{ $primaryButton }}" wire:loading.attr="disabled" wire:target="saveCompany">Guardar y continuar</button>
                    </div>
                </form>
            @elseif ($step === 2)
                <section class="{{ $card }}" aria-labelledby="w-consent">
                    <div class="flex flex-col gap-1.5">
                        <h2 id="w-consent" class="font-sans text-lg font-bold tracking-normal">Pide el consentimiento del administrador de Microsoft</h2>
                        <p class="text-[0.9375rem] text-fg-soft">El administrador de Microsoft 365 de {{ $current->name }} debe abrir este enlace una sola vez y aceptar. Sin este paso, sus usuarios no pueden entrar al portal.</p>
                    </div>

                    <div x-data="{ copied: false }" class="flex flex-col gap-1.5">
                        <label for="w-link" class="{{ $label }}">Enlace de consentimiento</label>
                        <div class="flex gap-2">
                            <input id="w-link" x-ref="link" type="text" readonly value="{{ $consentUrl }}" class="h-11 min-w-0 flex-1 rounded-[10px] border border-line bg-ground px-3 font-mono text-[0.8125rem] text-fg-soft">
                            <button type="button" class="{{ $secondaryButton }} shrink-0"
                                    x-on:click="navigator.clipboard.writeText($refs.link.value).then(() => { copied = true; setTimeout(() => copied = false, 2500) })">
                                <span x-text="copied ? 'Copiado' : 'Copiar'">Copiar</span>
                            </button>
                        </div>
                        <p class="sr-only" aria-live="polite" x-text="copied ? 'Enlace copiado' : ''"></p>
                    </div>

                    @if ($verified)
                        <div role="status" class="flex items-center gap-3 rounded-[14px] bg-status-active-bg px-[18px] py-4 text-status-active">
                            <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5 9-10"/></svg>
                            <span class="font-bold">Consentimiento verificado</span>
                        </div>
                    @else
                        <div wire:poll.60s="checkConsent" role="status"
                             @class([
                                 'flex flex-wrap items-center gap-4 rounded-[14px] px-[18px] py-4',
                                 'bg-status-maintenance-bg text-status-maintenance' => $consentStatus !== 'unknown',
                                 'bg-status-failure-bg text-status-failure' => $consentStatus === 'unknown',
                             ])>
                            <span class="size-2.5 shrink-0 rounded-full bg-current motion-safe:animate-pulse" aria-hidden="true"></span>
                            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                @if ($consentStatus === 'unknown')
                                    <span class="font-bold">No se pudo comprobar</span>
                                    <span class="text-sm">Microsoft no respondió con un resultado claro. Revisa que el secreto de la app esté configurado en el servidor o inténtalo más tarde. La empresa no se marca como verificada.</span>
                                @else
                                    <span class="font-bold">Esperando el consentimiento</span>
                                    <span class="text-sm">Lo comprobamos solos cada minuto mientras esta página esté abierta. Última revisión: {{ $lastCheckedAt ? \Illuminate\Support\Carbon::createFromTimestamp($lastCheckedAt)->locale('es')->diffForHumans() : 'todavía no' }}.</span>
                                @endif
                            </span>
                            <button type="button" wire:click="checkConsent" wire:loading.attr="disabled" wire:target="checkConsent" class="inline-flex min-h-touch items-center rounded-control bg-surface px-4 text-sm font-semibold text-fg">Comprobar ahora</button>
                        </div>
                    @endif

                    <div class="flex flex-col gap-2.5">
                        <h3 class="font-sans text-[0.9375rem] font-bold tracking-normal">Qué va a aceptar el administrador</h3>
                        <ul role="list" class="flex flex-col gap-2 text-sm text-fg-soft">
                            <li class="flex items-center gap-2.5"><span class="size-2 shrink-0 rounded-full bg-fg-soft" aria-hidden="true"></span>Iniciar sesión y leer el perfil básico del usuario <code class="rounded-md bg-subtle px-1.5 py-0.5 text-xs">User.Read</code></li>
                            <li class="flex items-center gap-2.5"><span class="size-2 shrink-0 rounded-full bg-fg-soft" aria-hidden="true"></span>Leer a qué grupos pertenece cada usuario <code class="rounded-md bg-subtle px-1.5 py-0.5 text-xs">GroupMember.Read.All</code></li>
                        </ul>
                    </div>

                    <div class="flex flex-wrap items-center justify-end gap-3 border-t border-line pt-4">
                        <a href="{{ route('admin.companies.index') }}" class="mr-auto text-sm font-semibold text-fg-soft underline-offset-4 hover:underline">Terminar más tarde</a>
                        @unless ($verified)
                            <span id="w-next-help" class="text-[0.8125rem] text-fg-muted">Se habilita cuando llegue el consentimiento</span>
                        @endunless
                        <button type="button" wire:click="goToStep(3)" @disabled(! $verified) @unless ($verified) aria-describedby="w-next-help" @endunless class="{{ $primaryButton }}">Siguiente: grupos</button>
                    </div>
                </section>
            @elseif ($step === 3)
                <section class="{{ $card }}" aria-labelledby="w-groups">
                    <div class="flex flex-col gap-1.5">
                        <h2 id="w-groups" class="font-sans text-lg font-bold tracking-normal">Crea los grupos APP-… en su Microsoft 365</h2>
                        <p class="text-[0.9375rem] text-fg-soft">El portal da permisos según la pertenencia a estos grupos de seguridad. Deben existir en el inquilino de {{ $current->name }}.</p>
                    </div>
                    <ul role="list" class="flex flex-col gap-2">
                        <li class="flex flex-wrap items-center justify-between gap-2 rounded-control border border-line px-4 py-3">
                            <code class="font-mono text-sm font-bold">APP-&lt;App&gt;</code>
                            <span class="text-sm text-fg-muted">Un grupo de seguridad por cada aplicación del catálogo que use esta empresa, p. ej. APP-Cartera</span>
                        </li>
                    </ul>
                    <p class="text-[0.8125rem] text-fg-muted">Solo cuentan los grupos de seguridad: los grupos de Microsoft 365 y los de Teams no dan acceso. Los administradores del portal no se crean aquí: vienen solo del grupo configurado en la organización de Segurex.</p>

                    <div class="flex flex-col gap-1.5">
                        <span class="{{ $label }}">Comando para un administrador de Microsoft 365 de {{ $current->name }}</span>
                        <pre class="overflow-x-auto rounded-control bg-fg p-4 text-[0.8125rem] text-on-fg"><code>.\crear-grupos-app.ps1 -TenantId {{ $current->tenant_id }} -Grupos 'APP-&lt;App&gt;'</code></pre>
                        <p class="text-[0.8125rem] text-fg-muted">El script está en <code>docs/scripts/crear-grupos-app.ps1</code>. Necesita PowerShell con el módulo Microsoft.Graph y solo crea los grupos que falten.</p>
                    </div>

                    <div class="flex flex-wrap items-center justify-end gap-3 border-t border-line pt-4">
                        <button type="button" wire:click="goToStep(2)" class="{{ $secondaryButton }} mr-auto">Atrás</button>
                        <a href="{{ route('admin.companies.index') }}" class="text-sm font-semibold text-fg-soft underline-offset-4 hover:underline">Terminar más tarde</a>
                        <button type="button" wire:click="goToStep(4)" class="{{ $primaryButton }}">Siguiente: activar</button>
                    </div>
                </section>
            @else
                <section class="{{ $card }}" aria-labelledby="w-activate">
                    <div class="flex flex-col gap-1.5">
                        <h2 id="w-activate" class="font-sans text-lg font-bold tracking-normal">Activa {{ $current->name }}</h2>
                        <p class="text-[0.9375rem] text-fg-soft">Al activarla, todos los usuarios de su organización de Microsoft podrán iniciar sesión en el portal. Avisaremos por correo a los administradores.</p>
                    </div>
                    @error('activation') <p class="{{ $error }}" role="alert">{{ $message }}</p> @enderror
                    <div class="flex flex-wrap items-center justify-end gap-3 border-t border-line pt-4">
                        <button type="button" wire:click="goToStep(3)" class="{{ $secondaryButton }} mr-auto">Atrás</button>
                        <button type="button" wire:click="activate" wire:loading.attr="disabled" wire:target="activate" class="{{ $primaryButton }}">Activar empresa</button>
                    </div>
                </section>
            @endif
            @if ($step !== 4)
                @error('activation') <p class="{{ $error }}" role="alert">{{ $message }}</p> @enderror
            @endif
        </div>

        <aside class="flex flex-col gap-4" aria-label="Resumen">
            <section class="flex flex-col gap-3.5 rounded-card border border-line bg-surface p-5">
                <div class="flex items-center gap-3">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-[11px] font-display text-lg font-bold text-white" style="background-color: {{ $palette->button }}" aria-hidden="true">{{ mb_strtoupper(mb_substr($summaryName, 0, 1)) }}</span>
                    <span class="flex min-w-0 flex-col leading-tight">
                        <span class="truncate font-bold">{{ $summaryName }}</span>
                        <span class="text-[0.8125rem] text-fg-muted">{{ $current ? 'Registrada, sin activar' : 'Sin guardar' }}</span>
                    </span>
                </div>
                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                    <dt class="text-fg-muted">Inquilino</dt><dd class="min-w-0 font-mono text-xs break-all">{{ $summaryTenant }}</dd>
                    <dt class="text-fg-muted">Dominios</dt><dd class="min-w-0 break-words">{{ $summaryDomains === [] ? '—' : implode(', ', $summaryDomains) }}</dd>
                    <dt class="text-fg-muted">Colores</dt>
                    <dd class="flex gap-1.5">
                        @foreach ([$palette->primary, $palette->accent, $palette->dark] as $swatch)
                            <span class="size-5 rounded-md border border-line" style="background-color: {{ $swatch }}"></span>
                        @endforeach
                    </dd>
                    <dt class="text-fg-muted">Estado</dt><dd>Inactiva hasta el paso 4</dd>
                </dl>
            </section>
            <section class="flex gap-3 rounded-card border border-line bg-surface p-5">
                <svg class="mt-0.5 size-5 shrink-0 text-fg-soft" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
                <div class="flex flex-col gap-1">
                    <span class="text-[0.9375rem] font-bold">Esto abre la puerta a toda la organización</span>
                    <span class="text-sm text-fg-soft">Por eso solo el súper administrador puede hacerlo. Al guardar te pediremos confirmar con Microsoft, quedará en auditoría y avisaremos por correo a los administradores.</span>
                </div>
            </section>
        </aside>
    </div>
</div>
