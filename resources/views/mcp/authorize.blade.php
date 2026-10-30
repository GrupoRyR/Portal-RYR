{{--
    Consent screen of the Claude connector (Passport::authorizationView,
    App\Http\Controllers\Mcp\AuthorizeController), shown every time an
    OAuth client asks to connect. Passport passes $client, $user, $scopes,
    $request and $authToken; the redirect URI was already checked against
    the client's (only claude.ai's callback is ever registered). «Aprobar»
    records the connection (App\Mcp\Connections); «Rechazar» sends
    access_denied back. No JavaScript; no account switcher here, so the
    account shown is the one that approves.
--}}
@use('App\Models\Company')
@php($redirectHost = parse_url((string) $request->query('redirect_uri'), PHP_URL_HOST) ?: '—')
@php($registeredAt = $client->created_at?->timezone(config('app.timezone'))->locale('es')->isoFormat('D [de] MMMM [de] YYYY [a las] h:mm a'))
<x-layouts.app title="Conectar Claude" brand-band noindex>
    <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-control focus:bg-surface focus:px-4 focus:py-3 focus:font-semibold">Saltar al contenido</a>

    <header class="brand-band">
        <div class="mx-auto flex max-w-[1440px] items-center px-4 py-2 md:px-8 lg:px-10">
            <span class="flex min-h-touch min-w-0 items-center">
                <x-brand-logo on-band />
            </span>
        </div>
    </header>

    <main id="contenido" class="mx-auto flex max-w-[680px] flex-col gap-6 px-4 pt-4 pb-12 md:px-8 md:pt-8">
        <div class="flex flex-col gap-2">
            <h1 class="text-[1.75rem] leading-tight font-bold md:text-[2.125rem]">¿Conectar «{{ $client->name }}» con el portal?</h1>
            <p class="text-[0.9375rem] leading-relaxed text-fg-soft">Después de aprobar, volverás a <span class="font-semibold text-fg">{{ $redirectHost }}</span>.@if ($registeredAt) Registrado el {{ $registeredAt }}.@endif</p>
        </div>

        {{-- Registering a client is public: anyone could send this link. --}}
        <p role="note" class="rounded-control border border-line bg-surface px-4 py-3 text-sm font-semibold">Aprueba solo si acabas de agregar el conector en tu propio Claude. Si no lo pediste, elige Rechazar.</p>

        <section aria-labelledby="cuenta" class="flex flex-col gap-3 rounded-card border border-line bg-surface p-5 md:p-6">
            <h2 id="cuenta" class="text-base font-semibold">La cuenta que usará</h2>
            <div class="flex flex-col">
                <span class="font-semibold">{{ $user->name }}</span>
                <span class="truncate text-[0.8125rem] text-fg-muted">{{ $user->email }}</span>
                <span class="text-[0.8125rem] font-semibold text-brand-ink">{{ Company::displayName($user->company) }}</span>
            </div>
        </section>

        <section aria-labelledby="lectura" class="flex flex-col gap-3 rounded-card border border-line bg-surface p-5 md:p-6">
            <h2 id="lectura" class="text-base font-semibold">Solo lectura: lo que podrá consultar</h2>
            <ul role="list" class="flex flex-col gap-2.5 text-[0.9375rem] leading-relaxed text-fg-soft">
                <li>Tus tareas y aprobaciones.</li>
                <li>Los anuncios que no has leído.</li>
                <li>Las próximas fechas: festivos, eventos, cumpleaños y aniversarios.</li>
                <li>El directorio de personas de tu empresa.</li>
                <li>Tus apps del portal.</li>
            </ul>
            <p class="text-[0.9375rem] leading-relaxed text-fg-soft">Solo verá lo que tú ya ves en el portal. No podrá cambiar nada. Puedes desconectarlo cuando quieras.</p>
        </section>

        <div class="flex flex-wrap items-center gap-3">
            <form method="POST" action="{{ route('passport.authorizations.approve') }}">
                @csrf
                <input type="hidden" name="state" value="{{ $request->query('state') }}">
                <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="inline-flex min-h-touch cursor-pointer items-center rounded-control bg-brand-button px-5 font-semibold text-white">Aprobar</button>
            </form>
            <form method="POST" action="{{ route('passport.authorizations.deny') }}">
                @csrf
                @method('DELETE')
                <input type="hidden" name="state" value="{{ $request->query('state') }}">
                <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="inline-flex min-h-touch cursor-pointer items-center rounded-control border border-line-strong px-5 font-semibold">Rechazar</button>
            </form>
        </div>
    </main>
</x-layouts.app>
