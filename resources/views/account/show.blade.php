{{--
    «Cuentas vinculadas» (App\Http\Controllers\Account\AccountController::show):
    the accounts of the signed-in user's person, each with «Desvincular»
    (a confirmation page first), and «Vincular otra empresa». No Livewire or
    Alpine. $accounts is empty when the account is not linked. «Mi cumpleaños»
    (day, month and whether the company sees it) when $birthdayMonths is set;
    without it, only «Quitar mi cumpleaños» for a birthday still stored.
    «Conexiones con Claude» when $claudeConnections is set (connector on).
--}}
@use('App\Models\Company')
<x-layouts.app title="Cuentas vinculadas" brand-band noindex>
    <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-control focus:bg-surface focus:px-4 focus:py-3 focus:font-semibold">Saltar al contenido</a>

    <x-brand-bar />

    <main id="contenido" class="mx-auto flex max-w-[680px] flex-col gap-6 px-4 pt-4 pb-12 md:px-8 md:pt-8">
        <div class="flex flex-col gap-2">
            <a href="{{ route('home') }}" class="-ml-1 inline-flex min-h-touch items-center gap-1.5 self-start pr-2 text-sm font-semibold text-fg-soft underline-offset-4 hover:underline">
                <x-home.icon name="chevron-left" stroke-width="2" class="size-[18px]" />Volver al inicio
            </a>
            <h1 class="text-[1.75rem] leading-tight font-bold md:text-[2.125rem]">Cuentas vinculadas</h1>
        </div>

        @if (is_string($status = session('status')) && $status !== '')
            <p role="status" data-flash class="rounded-control border border-line bg-surface px-4 py-3 text-sm font-semibold">{{ $status }}</p>
        @endif

        @if ($accounts->isEmpty())
            <section class="flex flex-col gap-5 rounded-card border border-line bg-surface p-5 md:p-6">
                <div class="flex flex-col gap-2">
                    <p class="text-base leading-relaxed font-semibold">Aún no tienes cuentas vinculadas.</p>
                    <p class="text-[0.9375rem] leading-relaxed text-fg-soft">Si también tienes una cuenta de Microsoft en otra empresa del grupo, vincúlala con esta para cambiar de empresa desde el encabezado, sin cerrar la sesión.</p>
                </div>
                <a href="{{ route('account.link') }}" class="inline-flex min-h-touch items-center gap-2 self-start rounded-control bg-brand-button px-5 font-semibold text-white">
                    <x-home.icon name="link" stroke-width="2" class="size-[18px]" />Vincular otra empresa
                </a>
            </section>
        @else
            <section aria-labelledby="tus-cuentas" class="flex flex-col gap-3">
                <h2 id="tus-cuentas" class="sr-only">Tus cuentas</h2>
                <p class="text-[0.9375rem] leading-relaxed text-fg-soft">Puedes cambiar entre estas cuentas desde el encabezado. Cada una conserva sus propios permisos, aplicaciones y favoritos.</p>
                <ul role="list" class="flex flex-col divide-y divide-line rounded-card border border-line bg-surface">
                    @foreach ($accounts as $account)
                        @php($isCurrent = $account->is($current))
                        <li data-linked-account @if ($isCurrent) aria-current="true" @endif class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-5 py-3">
                            <span class="flex min-w-0 flex-col">
                                <span class="font-semibold">{{ Company::displayName($account->company) }}</span>
                                <span class="truncate text-[0.8125rem] text-fg-muted">{{ $account->email }}</span>
                                @if ($isCurrent)
                                    <span class="text-[0.8125rem] font-semibold text-brand-ink">Esta cuenta</span>
                                @endif
                            </span>
                            <a href="{{ route('account.unlink.confirm', $account) }}" class="inline-flex min-h-touch items-center text-sm font-semibold text-status-failure underline-offset-4 hover:underline">
                                Desvincular<span class="sr-only"> la cuenta de {{ Company::displayName($account->company) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
                <a href="{{ route('account.link') }}" class="inline-flex min-h-touch items-center gap-2 self-start rounded-control bg-brand-button px-5 font-semibold text-white">
                    <x-home.icon name="link" stroke-width="2" class="size-[18px]" />Vincular otra empresa
                </a>
            </section>
        @endif

        <p class="text-[0.8125rem] leading-relaxed text-fg-muted">Solo puedes vincular una cuenta por empresa, y solo de empresas activas en el portal. Las cuentas de invitado y la cuenta de emergencia no se pueden vincular.</p>

        {{-- «Mi cumpleaños» (App\Http\Controllers\Account\BirthdayController): saving only with a
             company directory; «Quitar» whenever one is stored, so it can always be removed. --}}
        @if ($birthdayMonths || $current->birthday_month !== null)
            {{-- One-line @php only: this file already uses @php(…), and Blade mixes it up with an @php block. --}}
            @php($select = 'h-12 rounded-control border border-line-strong bg-surface px-3 text-base text-fg')
            @php($savedDay = (string) old('birthday_day', $current->birthday_day))
            @php($savedMonth = (string) old('birthday_month', $current->birthday_month))
            @php($shown = $errors->any() ? (bool) old('show_birthday') : (bool) $current->show_birthday)
            <section id="cumpleanos" aria-labelledby="cumpleanos-titulo" class="flex scroll-mt-6 flex-col gap-4 rounded-card border border-line bg-surface p-5 md:p-6">
                <div class="flex flex-col gap-2">
                    <h2 id="cumpleanos-titulo" class="font-sans text-lg font-bold tracking-normal">Mi cumpleaños</h2>
                    @if ($birthdayMonths)
                        <p class="text-[0.9375rem] leading-relaxed text-fg-soft">Solo guardamos el día y el mes, nunca el año. Solo lo ven las personas de tu empresa, en «Mi día» del inicio, y solo si marcas la casilla. Puedes quitarlo cuando quieras.</p>
                        @if ($label = $current->birthdayLabel())
                            <p class="text-sm font-semibold">Tu cumpleaños: {{ $label }}. {{ $current->show_birthday ? 'Se muestra en «Mi día» a las personas de tu empresa.' : 'Nadie más lo ve.' }}</p>
                        @endif
                    @else
                        {{-- No directory any more (guest, no company or an inactive one): nobody sees it, and it can only be removed. --}}
                        <p class="text-[0.9375rem] leading-relaxed text-fg-soft">Tienes guardado tu cumpleaños ({{ $current->birthdayLabel() }}), pero esta cuenta ya no ve el directorio de una empresa, así que nadie lo ve. Puedes quitarlo.</p>
                    @endif
                </div>

                @if ($birthdayMonths)
                <form method="POST" action="{{ route('account.birthday.update') }}" class="flex flex-col gap-4" novalidate>
                    @csrf
                    <div class="flex flex-wrap gap-3">
                        <div class="flex flex-col gap-1.5">
                            <label for="birthday_day" class="text-sm font-semibold">Día</label>
                            <select id="birthday_day" name="birthday_day" required class="{{ $select }} w-24" @error('birthday_day') aria-invalid="true" aria-describedby="birthday_day-error" @enderror>
                                <option value="">Día</option>
                                @foreach (range(1, 31) as $day)
                                    <option value="{{ $day }}" @selected($savedDay === (string) $day)>{{ $day }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label for="birthday_month" class="text-sm font-semibold">Mes</label>
                            <select id="birthday_month" name="birthday_month" required class="{{ $select }} w-44" @error('birthday_month') aria-invalid="true" aria-describedby="birthday_month-error" @enderror>
                                <option value="">Mes</option>
                                @foreach ($birthdayMonths as $number => $name)
                                    <option value="{{ $number }}" @selected($savedMonth === (string) $number)>{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    @error('birthday_day')
                        <p id="birthday_day-error" class="text-sm font-semibold text-status-failure">{{ $message }}</p>
                    @enderror
                    @error('birthday_month')
                        <p id="birthday_month-error" class="text-sm font-semibold text-status-failure">{{ $message }}</p>
                    @enderror

                    <label class="flex items-start gap-3 text-[0.9375rem] leading-relaxed">
                        <input type="checkbox" name="show_birthday" value="1" @checked($shown) class="mt-1 size-[18px] shrink-0 accent-fg">
                        <span>Mostrar mi cumpleaños a las personas de mi empresa</span>
                    </label>

                    <button type="submit" class="inline-flex min-h-touch cursor-pointer items-center self-start rounded-control bg-brand-button px-5 font-semibold text-white">Guardar</button>
                </form>
                @endif

                @if ($current->birthday_month !== null)
                    <form method="POST" action="{{ route('account.birthday.destroy') }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="inline-flex min-h-touch cursor-pointer items-center text-sm font-semibold text-status-failure underline-offset-4 hover:underline">Quitar mi cumpleaños</button>
                    </form>
                @endif
            </section>
        @endif

        {{-- «Conexiones con Claude» (App\Http\Controllers\Account\ClaudeConnectionController): the active
             connections this account takes part in; null (hidden) only while the connector is off and there are none.
             With the connector off they stay listed and «Desconectar» works, with a note ($claudeOff). --}}
        @if ($claudeConnections !== null)
            @php($longDate = 'D [de] MMMM [de] YYYY')
            <section id="claude" aria-labelledby="claude-titulo" class="flex scroll-mt-6 flex-col gap-4 rounded-card border border-line bg-surface p-5 md:p-6">
                <div class="flex flex-col gap-2">
                    <h2 id="claude-titulo" class="font-sans text-lg font-bold tracking-normal">Conexiones con Claude</h2>
                    <p class="text-[0.9375rem] leading-relaxed text-fg-soft">Claude lee, solo para consultar, lo que estas cuentas ven en el portal. Al desconectar una conexión se corta completa, con todas sus cuentas; para volver a usarla hay que conectarla de nuevo desde Claude.</p>
                </div>

                @if ($claudeOff && $claudeConnections->isNotEmpty())
                    <p role="note" class="rounded-control border border-line bg-surface px-4 py-3 text-sm font-semibold">
                        {{ $claudeConnections->count() === 1
                            ? 'El conector de Claude está apagado: esta conexión no funciona por ahora, pero vuelve a funcionar si lo encienden. Puedes desconectarla.'
                            : 'El conector de Claude está apagado: estas conexiones no funcionan por ahora, pero vuelven a funcionar si lo encienden. Puedes desconectarlas.' }}
                    </p>
                @endif

                @if ($claudeConnections->isEmpty())
                    <p class="text-[0.9375rem] font-semibold">Esta cuenta no tiene conexiones con Claude.</p>
                @else
                    <ul role="list" class="flex flex-col divide-y divide-line rounded-card border border-line">
                        @foreach ($claudeConnections as $connection)
                            <li data-claude-connection class="flex flex-col gap-3 px-5 py-4">
                                <span class="flex min-w-0 flex-col">
                                    <span class="font-semibold">{{ $connection->client?->name ?? 'Cliente eliminado' }}</span>
                                    @if ($connection->client?->created_at)
                                        <span class="text-[0.8125rem] text-fg-muted">Cliente registrado el {{ $connection->client->created_at->timezone(config('app.timezone'))->locale('es')->isoFormat($longDate) }}</span>
                                    @endif
                                </span>
                                <ul role="list" aria-label="Cuentas de la conexión" class="flex flex-col gap-1.5">
                                    @foreach (\App\Mcp\Connections::accountsOf($connection) as $account)
                                        <li class="flex min-w-0 flex-col text-sm">
                                            <span class="font-semibold">{{ $account->name }}</span>
                                            <span class="truncate text-[0.8125rem] text-fg-muted">{{ $account->email }} · {{ Company::displayName($account->company) }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                                <span class="flex flex-col text-[0.8125rem] text-fg-muted">
                                    <span>Conectada el {{ $connection->created_at->timezone(config('app.timezone'))->locale('es')->isoFormat($longDate.', HH:mm') }}</span>
                                    <span>{{ $connection->last_used_at ? 'Último uso: '.$connection->last_used_at->timezone(config('app.timezone'))->locale('es')->isoFormat($longDate.', HH:mm') : 'Aún no se ha usado' }}</span>
                                </span>
                                <form method="POST" action="{{ route('account.claude.disconnect', $connection) }}">
                                    @csrf
                                    <button type="submit" class="inline-flex min-h-touch cursor-pointer items-center text-sm font-semibold text-status-failure underline-offset-4 hover:underline">
                                        Desconectar<span class="sr-only"> {{ $connection->client?->name }}</span>
                                    </button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif
    </main>
</x-layouts.app>
