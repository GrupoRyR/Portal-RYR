{{-- «Quién la ve» of the app editor (docs/diseno/AdminAppEdit.dc.html). Each change is saved at once. --}}
@php
    $field = 'h-11 w-full rounded-[10px] border border-line-strong bg-surface px-3 text-[0.9375rem] text-fg';
    $label = 'text-[0.8125rem] font-semibold';
    $error = 'text-[0.8125rem] font-semibold text-status-failure';
    $secondary = 'inline-flex min-h-touch items-center justify-center rounded-control border border-line-strong bg-surface px-4 text-sm font-semibold hover:bg-subtle';
    $primary = 'inline-flex min-h-touch items-center justify-center rounded-control bg-brand-button px-4 text-sm font-semibold text-white';
    $companyLabel = fn ($company) => $company ? ($company->short_name ?: $company->name) : 'empresa borrada';
@endphp
<section class="flex flex-col gap-[18px] rounded-card border border-line bg-surface p-5 sm:p-6" aria-labelledby="e-rules">
    <div class="flex flex-col gap-1">
        <h2 id="e-rules" class="font-sans text-lg font-bold tracking-normal">Quién la ve</h2>
        <p class="text-[0.8125rem] text-fg-muted">Basta con cumplir una regla. La app también debe validar el acceso por su cuenta.</p>
    </div>

    @if ($rules->isEmpty())
        <p class="rounded-[12px] bg-subtle px-4 py-3 text-[0.9375rem] text-fg-soft">Nadie la ve todavía. Agrega al menos una regla.</p>
    @else
        <ul role="list" class="flex flex-col divide-y divide-line rounded-[14px] border border-line">
            @foreach ($rules as $rule)
                @php
                    $user = $rule->type === 'user' ? $users->get((int) $rule->value) : null;
                    $title = match ($rule->type) {
                        'group' => $rule->value,
                        'user' => $user?->name ?? 'Usuario borrado',
                        default => 'Toda la empresa',
                    };
                    $detail = match ($rule->type) {
                        'group' => 'Grupo de Microsoft · '.$companyLabel($rule->company),
                        'user' => 'Usuario · '.($user?->email ? $user->email.' · ' : '').$companyLabel($rule->company),
                        default => 'Todas las personas de '.$companyLabel($rule->company),
                    };
                    $applies = $linked === null || in_array((int) $rule->company_id, $linked, true);
                @endphp
                <li wire:key="rule-{{ $rule->id }}" class="flex items-center gap-3 px-3.5 py-2.5">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-subtle text-fg-soft" aria-hidden="true">
                        @if ($rule->type === 'group')
                            <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 7M18 14.5a6.5 6.5 0 0 1 3.5 5.5"/></svg>
                        @elseif ($rule->type === 'user')
                            <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                        @else
                            <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21V5a1 1 0 0 1 1-1h9a1 1 0 0 1 1 1v16M15 9h4a1 1 0 0 1 1 1v11M3 21h18M8 8h3M8 12h3M8 16h3"/></svg>
                        @endif
                    </span>
                    <span class="flex min-w-0 flex-1 flex-col">
                        <span class="truncate text-[0.9375rem] font-semibold">{{ $title }}</span>
                        <span class="text-[0.8125rem] break-words text-fg-muted">{{ $detail }}</span>
                        @if (in_array((int) $rule->id, $microsoft365Only, true))
                            <span class="text-[0.8125rem] font-semibold text-status-failure">Este grupo es de Microsoft 365 y no da acceso: debe ser un grupo de seguridad.</span>
                        @endif
                        @unless ($applies)
                            <span class="text-[0.8125rem] font-semibold text-status-maintenance">La app no está activa en {{ $companyLabel($rule->company) }}: esta regla no aplica.</span>
                        @endunless
                    </span>
                    <button type="button" wire:click="remove({{ $rule->id }})" wire:confirm="¿Quitar esta regla? Quien solo entraba por ella deja de ver la app."
                            aria-label="Quitar regla {{ $title }}"
                            class="inline-flex size-touch shrink-0 items-center justify-center rounded-[10px] text-fg-soft hover:bg-subtle hover:text-status-failure">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
                    </button>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($mode === '')
        <div class="flex flex-wrap gap-2.5">
            <button type="button" wire:click="open('group')" class="{{ $secondary }}">+ Grupo de Microsoft</button>
            <button type="button" wire:click="open('user')" class="{{ $secondary }}">+ Usuario</button>
            <button type="button" wire:click="open('company')" class="{{ $secondary }}">+ Toda la empresa</button>
        </div>
    @else
        <div role="group" aria-labelledby="rule-form-title" class="flex flex-col gap-4 rounded-[14px] border border-line-strong p-4">
            <h3 id="rule-form-title" class="font-sans text-[0.9375rem] font-bold tracking-normal">
                {{ ['group' => 'Agregar un grupo de Microsoft', 'user' => 'Agregar un usuario', 'company' => 'Agregar toda una empresa'][$mode] }}
            </h3>

            <div class="flex flex-col gap-1.5">
                <label for="rule-company" class="{{ $label }}">Empresa</label>
                <select id="rule-company" wire:model.live="companyId" class="{{ $field }}">
                    @foreach ($companies as $company)
                        <option value="{{ $company->id }}">{{ $company->name }}</option>
                    @endforeach
                </select>
                @error('companyId') <p class="{{ $error }}" role="alert">{{ $message }}</p> @enderror
            </div>

            @if ($mode === 'group')
                <div class="flex flex-col gap-1.5">
                    <label for="rule-group" class="{{ $label }}">Nombre del grupo</label>
                    <input id="rule-group" type="text" list="rule-group-options" maxlength="255" autocomplete="off" spellcheck="false"
                           wire:model.live.debounce.300ms="groupName" wire:keydown.enter.prevent="addGroup"
                           placeholder="APP-…" aria-describedby="rule-group-h" class="{{ $field }}">
                    <datalist id="rule-group-options">
                        @foreach ($suggestions as $suggestion)
                            <option value="{{ $suggestion }}">
                        @endforeach
                    </datalist>
                    <span id="rule-group-h" class="text-[0.8125rem] text-fg-muted">Tal como se llama en Microsoft 365. Solo cuentan los grupos de seguridad (no los de Teams ni los grupos de Microsoft 365). Se sugieren los grupos APP-… de seguridad que ya tienen personas de esta empresa.</span>
                    @error('groupName') <p class="{{ $error }}" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-wrap gap-2.5">
                    <button type="button" wire:click="addGroup" class="{{ $primary }}">Agregar grupo</button>
                    <button type="button" wire:click="cancel" class="{{ $secondary }}">Cancelar</button>
                </div>
            @elseif ($mode === 'user')
                <div class="flex flex-col gap-1.5">
                    <label for="rule-user" class="{{ $label }}">Buscar por nombre o correo</label>
                    <input id="rule-user" type="search" maxlength="100" autocomplete="off" wire:model.live.debounce.300ms="userSearch" wire:keydown.enter.prevent
                           aria-describedby="rule-user-h" aria-controls="rule-user-results" class="{{ $field }}">
                    <span id="rule-user-h" class="text-[0.8125rem] text-fg-muted">Escribe al menos 2 letras. Solo aparecen personas que ya entraron al portal.</span>
                    @error('userSearch') <p class="{{ $error }}" role="alert">{{ $message }}</p> @enderror
                </div>
                <div id="rule-user-results" aria-live="polite">
                    @if (mb_strlen(trim($userSearch)) >= 2)
                        @if ($matches->isEmpty())
                            <p class="text-sm text-fg-muted">
                                Nadie con ese nombre ha entrado al portal en esta empresa.
                                Solo aparecen las personas que ya iniciaron sesión al menos una vez.
                                Para dar acceso a alguien que aún no entra, usa <strong>+ Grupo de Microsoft</strong> o pídele que entre una vez.
                            </p>
                        @else
                            <ul role="list" class="flex flex-col divide-y divide-line rounded-[12px] border border-line">
                                @foreach ($matches as $match)
                                    <li wire:key="match-{{ $match->id }}" class="flex items-center gap-3 px-3 py-2">
                                        <span class="flex min-w-0 flex-1 flex-col">
                                            <span class="truncate text-sm font-semibold">{{ $match->name }}</span>
                                            <span class="truncate text-[0.8125rem] text-fg-muted">{{ $match->email }}</span>
                                        </span>
                                        <button type="button" wire:click="addUser({{ $match->id }})" class="{{ $secondary }} shrink-0">
                                            Agregar<span class="sr-only"> a {{ $match->name }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    @endif
                </div>
                <div>
                    <button type="button" wire:click="cancel" class="{{ $secondary }}">Cancelar</button>
                </div>
            @else
                <p class="text-[0.8125rem] text-fg-muted">Todas las personas de la empresa elegida verán la app.</p>
                <div class="flex flex-wrap gap-2.5">
                    <button type="button" wire:click="addCompany" class="{{ $primary }}">Agregar empresa</button>
                    <button type="button" wire:click="cancel" class="{{ $secondary }}">Cancelar</button>
                </div>
            @endif
        </div>
    @endif
</section>
