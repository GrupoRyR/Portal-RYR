{{--
    «Cuentas vinculadas» (App\Http\Controllers\Account\AccountController::show):
    the accounts of the signed-in user's person, each with «Desvincular»
    (a confirmation page first), and «Vincular otra empresa». No Livewire or
    Alpine. $accounts is empty when the account is not linked. «Mi cumpleaños»
    (day, month and whether the company sees it) when $birthdayMonths is set;
    without it, only «Quitar mi cumpleaños» for a birthday still stored.
    «Conexiones con Claude» when $claudeConnections is set (connector on).
    «Avisos en el celular» when $pushKey is set (VAPID settings present).
    Liquid Glass (odd/tasks/liquid-glass.md G9, resources/css/glass-pages.css):
    a title banner, then one g-card per section.
--}}
@use('App\Models\Company')
<x-layouts.app title="Cuentas vinculadas" glass assistant noindex>
    <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-control focus:bg-surface focus:px-4 focus:py-3 focus:font-semibold">Saltar al contenido</a>

    <x-portal-header />

    <main id="contenido" class="g-page">
        <div class="g-banner g-page__hello">
            <a href="{{ route('home') }}" class="g-page__back">
                <x-home.icon name="chevron-left" stroke-width="2" class="size-[18px]" />Volver al inicio
            </a>
            <h1>Cuentas vinculadas</h1>
        </div>

        @if (is_string($status = session('status')) && $status !== '')
            <p role="status" data-flash class="g-card g-page__flash">{{ $status }}</p>
        @endif

        <section aria-labelledby="tus-cuentas" class="g-card">
            <div class="g-card__head">
                <span class="g-mark" aria-hidden="true"></span>
                <h2 id="tus-cuentas">Tus cuentas</h2>
            </div>
            <div class="g-card__body">
                @if ($accounts->isEmpty())
                    <div class="flex flex-col gap-2">
                        <p class="g-page__strong">Aún no tienes cuentas vinculadas.</p>
                        <p class="g-page__text">Si también tienes una cuenta de Microsoft en otra empresa, vincúlala con esta para cambiar de empresa desde el encabezado, sin cerrar la sesión.</p>
                    </div>
                @else
                    <p class="g-page__text">Puedes cambiar entre estas cuentas desde el encabezado. Cada una conserva sus propios permisos, aplicaciones y favoritos.</p>
                    <ul role="list" class="g-page__rows">
                        @foreach ($accounts as $account)
                            @php($isCurrent = $account->is($current))
                            <li data-linked-account @if ($isCurrent) aria-current="true" @endif class="g-page__row">
                                <span class="g-page__who">
                                    <span>{{ Company::displayName($account->company) }}</span>
                                    <span>{{ $account->email }}</span>
                                    @if ($isCurrent)
                                        <span class="g-pill">Esta cuenta</span>
                                    @endif
                                </span>
                                <a href="{{ route('account.unlink.confirm', $account) }}" class="g-btn g-btn--danger g-btn--small">
                                    Desvincular<span class="sr-only"> la cuenta de {{ Company::displayName($account->company) }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <a href="{{ route('account.link') }}" class="g-btn g-btn--primary self-start">
                    <x-home.icon name="link" stroke-width="2" class="size-[18px]" />Vincular otra empresa
                </a>
                <p class="g-page__fine">Solo puedes vincular una cuenta por empresa, y solo de empresas activas en el portal. Las cuentas de invitado y la cuenta de emergencia no se pueden vincular.</p>
            </div>
        </section>

        {{-- «Mi cumpleaños» (App\Http\Controllers\Account\BirthdayController): saving only with a
             company directory; «Quitar» whenever one is stored, so it can always be removed. --}}
        @if ($birthdayMonths || $current->birthday_month !== null)
            {{-- One-line @php only: this file already uses @php(…), and Blade mixes it up with an @php block. --}}
            @php($savedDay = (string) old('birthday_day', $current->birthday_day))
            @php($savedMonth = (string) old('birthday_month', $current->birthday_month))
            @php($shown = $errors->any() ? (bool) old('show_birthday') : (bool) $current->show_birthday)
            <section id="cumpleanos" aria-labelledby="cumpleanos-titulo" class="g-card">
                <div class="g-card__head">
                    <span class="g-mark" aria-hidden="true"></span>
                    <h2 id="cumpleanos-titulo">Mi cumpleaños</h2>
                </div>
                <div class="g-card__body">
                    <div class="flex flex-col gap-2">
                        @if ($birthdayMonths)
                            <p class="g-page__text">Solo guardamos el día y el mes, nunca el año. Solo lo ven las personas de tu empresa, en «Mi día» del inicio, y solo si marcas la casilla. Puedes quitarlo cuando quieras.</p>
                            @if ($label = $current->birthdayLabel())
                                <p class="g-page__strong">Tu cumpleaños: {{ $label }}. {{ $current->show_birthday ? 'Se muestra en «Mi día» a las personas de tu empresa.' : 'Nadie más lo ve.' }}</p>
                            @endif
                        @else
                            {{-- No directory any more (guest, no company or an inactive one): nobody sees it, and it can only be removed. --}}
                            <p class="g-page__text">Tienes guardado tu cumpleaños ({{ $current->birthdayLabel() }}), pero esta cuenta ya no ve el directorio de una empresa, así que nadie lo ve. Puedes quitarlo.</p>
                        @endif
                    </div>

                    @if ($birthdayMonths)
                    <form method="POST" action="{{ route('account.birthday.update') }}" class="flex flex-col gap-4" novalidate>
                        @csrf
                        <div class="flex flex-wrap gap-3">
                            <div class="g-page__field">
                                <label for="birthday_day">Día</label>
                                <select id="birthday_day" name="birthday_day" required class="g-select w-24" @error('birthday_day') aria-invalid="true" aria-describedby="birthday_day-error" @enderror>
                                    <option value="">Día</option>
                                    @foreach (range(1, 31) as $day)
                                        <option value="{{ $day }}" @selected($savedDay === (string) $day)>{{ $day }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="g-page__field">
                                <label for="birthday_month">Mes</label>
                                <select id="birthday_month" name="birthday_month" required class="g-select w-44" @error('birthday_month') aria-invalid="true" aria-describedby="birthday_month-error" @enderror>
                                    <option value="">Mes</option>
                                    @foreach ($birthdayMonths as $number => $name)
                                        <option value="{{ $number }}" @selected($savedMonth === (string) $number)>{{ $name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        @error('birthday_day')
                            <p id="birthday_day-error" class="g-page__error">{{ $message }}</p>
                        @enderror
                        @error('birthday_month')
                            <p id="birthday_month-error" class="g-page__error">{{ $message }}</p>
                        @enderror

                        <label class="g-page__check">
                            <input type="checkbox" name="show_birthday" value="1" @checked($shown)>
                            <span>Mostrar mi cumpleaños a las personas de mi empresa</span>
                        </label>

                        <button type="submit" class="g-btn g-btn--primary self-start px-6">Guardar</button>
                    </form>
                    @endif

                    @if ($current->birthday_month !== null)
                        <form method="POST" action="{{ route('account.birthday.destroy') }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="g-btn g-btn--danger g-btn--small">Quitar mi cumpleaños</button>
                        </form>
                    @endif
                </div>
            </section>
        @endif

        {{-- «Conexiones con Claude» (App\Http\Controllers\Account\ClaudeConnectionController): the active
             connections this account takes part in; null (hidden) only while the connector is off and there are none.
             With the connector off they stay listed and «Desconectar» works, with a note ($claudeOff). --}}
        @if ($claudeConnections !== null)
            @php($longDate = 'D [de] MMMM [de] YYYY')
            <section id="claude" aria-labelledby="claude-titulo" class="g-card">
                <div class="g-card__head">
                    <span class="g-mark" aria-hidden="true"></span>
                    <h2 id="claude-titulo">Conexiones con Claude</h2>
                </div>
                <div class="g-card__body">
                    <p class="g-page__text">Claude lee, solo para consultar, lo que estas cuentas ven en el portal. Al desconectar una conexión se corta completa, con todas sus cuentas; para volver a usarla hay que conectarla de nuevo desde Claude.</p>

                    @if ($claudeOff && $claudeConnections->isNotEmpty())
                        <p role="note" class="g-page__strong">
                            {{ $claudeConnections->count() === 1
                                ? 'El conector de Claude está apagado: esta conexión no funciona por ahora, pero vuelve a funcionar si lo encienden. Puedes desconectarla.'
                                : 'El conector de Claude está apagado: estas conexiones no funcionan por ahora, pero vuelven a funcionar si lo encienden. Puedes desconectarlas.' }}
                        </p>
                    @endif

                    @if ($claudeConnections->isEmpty())
                        <p class="g-page__strong">Esta cuenta no tiene conexiones con Claude.</p>
                    @else
                        <ul role="list" class="g-page__rows">
                            @foreach ($claudeConnections as $connection)
                                <li data-claude-connection class="g-page__row flex-col items-stretch">
                                    <span class="g-page__who">
                                        <span>{{ $connection->client?->name ?? 'Cliente eliminado' }}</span>
                                        @if ($connection->client?->created_at)
                                            <span>Cliente registrado el {{ $connection->client->created_at->timezone(config('app.timezone'))->locale('es')->isoFormat($longDate) }}</span>
                                        @endif
                                    </span>
                                    <ul role="list" aria-label="Cuentas de la conexión" class="flex flex-col gap-1.5">
                                        @foreach (\App\Mcp\Connections::accountsOf($connection) as $account)
                                            <li class="g-page__who text-sm">
                                                <span>{{ $account->name }}</span>
                                                <span>{{ $account->email }} · {{ Company::displayName($account->company) }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                    <span class="g-page__fine flex flex-col">
                                        <span>Conectada el {{ $connection->created_at->timezone(config('app.timezone'))->locale('es')->isoFormat($longDate.', HH:mm') }}</span>
                                        <span>{{ $connection->last_used_at ? 'Último uso: '.$connection->last_used_at->timezone(config('app.timezone'))->locale('es')->isoFormat($longDate.', HH:mm') : 'Aún no se ha usado' }}</span>
                                    </span>
                                    <form method="POST" action="{{ route('account.claude.disconnect', $connection) }}">
                                        @csrf
                                        <button type="submit" class="g-btn g-btn--danger g-btn--small">
                                            Desconectar<span class="sr-only"> {{ $connection->client?->name }}</span>
                                        </button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>
        @endif

        @if ($pushKey)
            @include('account.partials.push-optin', ['key' => $pushKey])
        @endif
    </main>
</x-layouts.app>
