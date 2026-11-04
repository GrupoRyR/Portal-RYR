{{--
    Consent screen of the Claude connector (Passport::authorizationView,
    App\Http\Controllers\Mcp\AuthorizeController), shown every time an
    OAuth client asks to connect. Passport passes $client, $user, $scopes,
    $request and $authToken; the redirect URI was already checked against
    the client's (only claude.ai's callback is ever registered). «Aprobar»
    records the connection (App\Mcp\Connections); «Rechazar» sends
    access_denied back. No JavaScript; no account switcher here, so the
    account shown first is the one that approves. AuthorizeController adds
    $offered (linked accounts that could be included) and $confirmed (when
    each was confirmed with Microsoft for this pending authorization).
    Liquid Glass (odd/tasks/liquid-glass.md G9): its own glass bar with the
    logo only (not the portal header: no switcher, menus or assistant).
--}}
@use('App\Models\Company')
@use('Illuminate\Support\Str')
@php($offered ??= collect())
@php($confirmed ??= [])
@php($redirectHost = parse_url((string) $request->query('redirect_uri'), PHP_URL_HOST) ?: '—')
@php($registeredAt = $client->created_at?->timezone(config('app.timezone'))->locale('es')->isoFormat('D [de] MMMM [de] YYYY [a las] h:mm a'))
<x-layouts.app title="Conectar Claude" glass noindex>
    <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-control focus:bg-surface focus:px-4 focus:py-3 focus:font-semibold">Saltar al contenido</a>

    <header class="g-topbar">
        <div class="mx-auto flex max-w-[1440px] items-center px-3 py-2 md:px-8 lg:px-10">
            <span class="flex min-h-touch min-w-0 items-center">
                <x-brand-logo />
            </span>
        </div>
    </header>

    <main id="contenido" class="g-page">
        <div class="g-banner g-page__hello">
            <h1>¿Conectar «{{ $client->name }}» con el portal?</h1>
            <p class="g-page__lead">Después de aprobar, volverás a <span class="font-semibold text-(--g-ink)">{{ $redirectHost }}</span>.@if ($registeredAt) Registrado el {{ Str::finish($registeredAt, '.') }}@endif</p>
        </div>

        {{-- Registering a client is public: anyone could send this link. --}}
        <p role="note" class="g-card g-page__flash">Aprueba solo si acabas de agregar el conector en tu propio Claude. Si no lo pediste, elige Rechazar.</p>

        @if (session('status'))
            <p role="status" class="g-card g-page__flash">{{ session('status') }}</p>
        @endif

        <section aria-labelledby="cuenta" class="g-card">
            <div class="g-card__head">
                <span class="g-mark" aria-hidden="true"></span>
                <h2 id="cuenta">{{ $offered->isEmpty() ? 'La cuenta que usará' : 'Las cuentas que usará' }}</h2>
            </div>
            <div class="g-card__body">
                <ul role="list" class="g-page__rows">
                    <li class="g-page__row">
                        <span class="g-page__who">
                            <span>{{ $user->name }}</span>
                            <span>{{ $user->email }}</span>
                            <span class="g-page__company">{{ Company::displayName($user->company) }}</span>
                        </span>
                        <span class="g-pill">Siempre incluida</span>
                    </li>
                    {{-- Linked accounts are only offered: each one joins after a
                         Microsoft sign-in of that very account (C2b). --}}
                    @foreach ($offered as $account)
                        @php($confirmedAt = $confirmed[$account->getKey()] ?? null)
                        <li class="g-page__row">
                            <span class="g-page__who">
                                <span>{{ $account->name }}</span>
                                <span>{{ $account->email }}</span>
                                <span class="g-page__company">{{ Company::displayName($account->company) }}</span>
                            </span>
                            @if ($confirmedAt)
                                <span class="flex flex-wrap items-center gap-3">
                                    <span class="g-pill good">Confirmada a las {{ $confirmedAt->locale('es')->isoFormat('h:mm a') }}</span>
                                    <form method="POST" action="{{ route('mcp.consent.exclude', $account) }}">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="auth_token" value="{{ $authToken }}">
                                        <button type="submit" class="g-btn g-btn--small">Quitar</button>
                                    </form>
                                </span>
                            @else
                                <form method="POST" action="{{ route('mcp.consent.include', $account) }}">
                                    @csrf
                                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                                    <button type="submit" class="g-btn g-btn--small">Incluir</button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if ($offered->isNotEmpty())
                    <p class="g-page__fine">Para incluir otra de tus cuentas, entra con Microsoft en esa cuenta. Solo se incluyen las que confirmes antes de aprobar.</p>
                @endif
            </div>
        </section>

        <section aria-labelledby="lectura" class="g-card">
            <div class="g-card__head">
                <span class="g-mark" aria-hidden="true"></span>
                <h2 id="lectura">Solo lectura: lo que podrá consultar</h2>
            </div>
            <div class="g-card__body">
                <ul role="list" class="g-page__bullets">
                    <li>Los anuncios que no has leído.</li>
                    <li>Las próximas fechas: festivos, eventos, cumpleaños y aniversarios.</li>
                    <li>El directorio de personas de tu empresa.</li>
                    <li>Tus apps del portal.</li>
                    <li>Tus tareas y aprobaciones.</li>
                </ul>
                <p class="g-page__text">Solo verá lo que tú ya ves en el portal. No podrá cambiar nada. Puedes desconectarlo cuando quieras.</p>
            </div>
        </section>

        <div class="g-page__actions">
            <form method="POST" action="{{ route('passport.authorizations.approve') }}">
                @csrf
                <input type="hidden" name="state" value="{{ $request->query('state') }}">
                <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="g-btn g-btn--primary px-6">Aprobar</button>
            </form>
            <form method="POST" action="{{ route('passport.authorizations.deny') }}">
                @csrf
                @method('DELETE')
                <input type="hidden" name="state" value="{{ $request->query('state') }}">
                <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="g-btn px-6">Rechazar</button>
            </form>
        </div>
    </main>
</x-layouts.app>
