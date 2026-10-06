{{-- Roles administration (docs/diseno/AdminRoles.dc.html): matrix, members per role, emergency account. --}}
@php
    $initials = function (?string $name): string {
        $words = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];

        return \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($words[0], 0, 1).(count($words) > 1 ? \Illuminate\Support\Str::substr(end($words), 0, 1) : ''));
    };
    $card = 'flex flex-col gap-3 rounded-card border border-line bg-surface px-5 py-[18px]';
    $avatar = 'flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-tint text-[13px] font-bold text-brand-ink';
    $code = 'rounded bg-subtle px-1.5 py-px font-mono text-[0.8125rem]';
    $company = fn ($user) => $user->company?->short_name ?: $user->company?->name;
@endphp
<div class="grid grid-cols-1 gap-6 min-[1200px]:grid-cols-[minmax(0,1fr)_400px] min-[1200px]:items-start">
    <section aria-labelledby="roles-matrix-title" class="min-w-0 overflow-hidden rounded-card border border-line bg-surface">
        <h2 id="roles-matrix-title" class="px-5 pt-[18px] pb-1.5 font-sans text-lg font-bold tracking-normal">Qué puede hacer cada rol</h2>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[560px] border-collapse text-sm">
                <thead>
                    <tr class="border-b border-line">
                        <th scope="col" class="px-4 py-3 text-left text-[0.8125rem] font-bold">Sección</th>
                        @foreach ($roles as $label)
                            <th scope="col" class="px-4 py-3 text-center text-[0.8125rem] font-bold">{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sections as $section)
                        <tr class="border-b border-line last:border-b-0">
                            <th scope="row" class="px-4 py-[11px] text-left font-semibold">
                                {{ $section->label }}
                                @unless ($section->available)
                                    <span class="ml-1 rounded-md bg-subtle px-1.5 text-[10px] leading-5 font-semibold whitespace-nowrap text-fg-muted">Próximamente</span>
                                @endunless
                            </th>
                            @foreach (array_keys($roles) as $role)
                                <td class="px-4 py-[11px] text-center">
                                    @if ($section->note($role))
                                        <span class="text-[0.8125rem] text-fg-soft">{{ $section->note($role) }}</span>
                                    @elseif ($section->allows($role))
                                        <span role="img" aria-label="Sí" class="font-bold text-status-active">✓</span>
                                    @else
                                        <span role="img" aria-label="No" class="text-fg-muted">—</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <div class="flex min-w-0 flex-col gap-4">
        <p role="status" @class(['rounded-control bg-status-active-bg px-4 py-3 text-sm font-semibold text-status-active', 'sr-only' => $notice === ''])>{{ $notice }}</p>

        <section aria-labelledby="role-super" class="{{ $card }}">
            <div class="flex items-baseline justify-between gap-3">
                <h2 id="role-super" class="font-sans text-base font-bold tracking-normal">Súper administrador</h2>
                <code class="text-xs text-fg-muted">super_admin</code>
            </div>
            <p class="text-[0.8125rem] leading-snug text-fg-soft">Lo recibe quien esté en los correos configurados del portal al entrar con Microsoft desde su propia empresa.</p>
            @forelse ($superAdmins as $member)
                <div wire:key="super-{{ $member->id }}" class="flex items-center gap-2.5">
                    <span class="{{ $avatar }}" aria-hidden="true">{{ $initials($member->name) }}</span>
                    <span class="flex min-w-0 flex-1 flex-col">
                        <span class="truncate text-sm font-semibold">{{ $member->name }}</span>
                        <span class="truncate text-xs text-fg-muted">{{ $member->email }}</span>
                    </span>
                    <svg class="size-4 shrink-0 text-fg-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="Fijo"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
                </div>
            @empty
                <span class="text-sm text-fg-muted">Nadie todavía.</span>
            @endforelse
        </section>

        <section aria-labelledby="role-portal" class="{{ $card }}">
            <div class="flex items-baseline justify-between gap-3">
                <h2 id="role-portal" class="font-sans text-base font-bold tracking-normal">Administradores</h2>
                <code class="text-xs text-fg-muted">portal_admin</code>
            </div>
            <p class="text-[0.8125rem] leading-snug text-fg-soft">Se asigna solo: entra quien esté en el grupo <code class="{{ $code }}">{{ config('portal.admin_group') }}</code> de su empresa al iniciar sesión. Para quitar a alguien, sácalo del grupo en Microsoft.</p>
            @forelse ($portalAdmins as $member)
                <div wire:key="portal-{{ $member->id }}" class="flex items-center gap-2.5">
                    <span class="{{ $avatar }}" aria-hidden="true">{{ $initials($member->name) }}</span>
                    <span class="flex min-w-0 flex-1 flex-col">
                        <span class="truncate text-sm font-semibold">{{ $member->name }}</span>
                        <span class="truncate text-xs text-fg-muted">{{ $company($member) ?? 'Sin empresa' }} · por grupo</span>
                    </span>
                </div>
            @empty
                <span class="text-sm text-fg-muted">Nadie todavía.</span>
            @endforelse
        </section>

        <section aria-labelledby="role-editor" class="{{ $card }}">
            <div class="flex items-baseline justify-between gap-3">
                <h2 id="role-editor" class="font-sans text-base font-bold tracking-normal">Editores de anuncios</h2>
                <code class="text-xs text-fg-muted">announcements_editor</code>
            </div>
            <p class="text-[0.8125rem] leading-snug text-fg-soft">Publican anuncios solo para su propia empresa y no ven el resto de la administración.</p>

            @forelse ($editors as $member)
                <div wire:key="editor-{{ $member->id }}" class="flex items-center gap-2.5">
                    <span class="{{ $avatar }}" aria-hidden="true">{{ $initials($member->name) }}</span>
                    <span class="flex min-w-0 flex-1 flex-col">
                        <span class="truncate text-sm font-semibold">{{ $member->name }}</span>
                        <span class="truncate text-xs text-fg-muted">{{ $member->email }}@if ($company($member)) · {{ $company($member) }}@endif</span>
                    </span>
                    <button type="button" wire:click="removeEditor({{ $member->id }})"
                            wire:confirm="¿Quitar a {{ $member->name }} de los editores de anuncios?"
                            class="inline-flex min-h-touch shrink-0 items-center rounded-[10px] px-3 text-sm font-semibold text-fg-soft hover:bg-subtle hover:text-status-failure">
                        Quitar<span class="sr-only"> a {{ $member->name }}</span>
                    </button>
                </div>
            @empty
                <span class="text-sm text-fg-muted">Nadie todavía.</span>
            @endforelse

            @if ($adding)
                <div class="flex flex-col gap-2 border-t border-line pt-3">
                    <label for="role-search" class="text-[0.8125rem] font-semibold">Buscar por nombre o correo</label>
                    <div class="flex gap-2">
                        <input id="role-search" type="search" autocomplete="off" maxlength="100"
                               wire:model.live.debounce.300ms="search" x-init="$el.focus()"
                               aria-describedby="role-search-hint"
                               class="h-11 min-w-0 flex-1 rounded-[10px] border border-line-strong bg-surface px-3 text-[0.9375rem] text-fg placeholder:text-fg-muted"
                               placeholder="Al menos 2 letras">
                        <button type="button" wire:click="stopAdding" class="inline-flex min-h-touch shrink-0 items-center rounded-control border border-line-strong bg-surface px-4 text-sm font-semibold hover:bg-subtle">Cancelar</button>
                    </div>
                    <p id="role-search-hint" class="text-xs text-fg-muted">Hasta {{ \App\Livewire\Admin\RoleManager::MAX_RESULTS }} resultados. Las cuentas de emergencia no aparecen.</p>

                    @if ($results->isNotEmpty())
                        <ul class="flex flex-col divide-y divide-line rounded-[14px] border border-line" aria-label="Resultados">
                            @foreach ($results as $result)
                                <li wire:key="result-{{ $result->id }}" class="flex items-center gap-2.5 py-1.5 pr-1.5 pl-3">
                                    <span class="flex min-w-0 flex-1 flex-col">
                                        <span class="truncate text-sm font-semibold">{{ $result->name }}</span>
                                        <span class="truncate text-xs text-fg-muted">{{ $result->email }} · {{ $company($result) ?? 'Sin empresa' }}</span>
                                    </span>
                                    <button type="button" wire:click="addEditor({{ $result->id }})"
                                            class="inline-flex min-h-touch shrink-0 items-center rounded-[10px] bg-brand-button px-3 text-sm font-semibold text-white">
                                        Agregar<span class="sr-only"> a {{ $result->name }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @elseif (mb_strlen(trim($search)) >= \App\Livewire\Admin\RoleManager::MIN_SEARCH)
                        <p class="text-sm text-fg-muted">Nadie coincide con «{{ trim($search) }}».</p>
                    @endif
                </div>
            @else
                <button type="button" wire:click="startAdding"
                        class="inline-flex min-h-touch items-center self-start rounded-[10px] border border-dashed border-line-strong px-3.5 text-sm font-semibold text-fg-soft hover:bg-subtle hover:text-fg">
                    + Agregar persona
                </button>
            @endif
        </section>

        <section aria-labelledby="role-emergency" class="{{ $card }}">
            <div class="flex items-center gap-3">
                <svg class="size-[22px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6z"/><path d="M12 9v4M12 16.5v.01"/></svg>
                <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <h2 id="role-emergency" class="font-sans text-[0.9375rem] font-bold tracking-normal">Cuenta de emergencia</h2>
                    @if ($emergency)
                        <span class="text-[0.8125rem] text-fg-muted">
                            Para entrar si Microsoft falla ·
                            {{ $emergency->two_factor_confirmed_at ? '2FA activo' : '2FA sin confirmar' }} ·
                            @if ($emergency->last_login_at)
                                usada por última vez el {{ $emergency->last_login_at->timezone(config('app.timezone'))->locale('es')->isoFormat('D [de] MMMM [de] YYYY') }}
                            @else
                                nunca usada
                            @endif
                        </span>
                        <span class="truncate text-[0.8125rem] text-fg-soft">{{ $emergency->email }}</span>
                    @else
                        <span class="text-[0.8125rem] text-fg-muted">No hay cuenta de emergencia.</span>
                    @endif
                </span>
            </div>
            <p class="text-xs leading-snug text-fg-muted">Se crea, se cambia o se desactiva solo desde el servidor con <code class="{{ $code }} text-xs">php artisan portal:emergency-account</code>.</p>
        </section>
    </div>
</div>
