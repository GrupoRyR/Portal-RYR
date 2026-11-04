{{-- Emergency sign-in, step 2 (authenticator code). --}}
<x-layouts.app title="Acceso de emergencia" noindex glass>
    <main class="mx-auto flex min-h-dvh w-full max-w-md flex-col justify-center px-4 py-12">
        <section class="g-card flex flex-col gap-8 px-8 py-9 max-sm:px-6">
            <div class="flex flex-col gap-2">
                <h1 class="text-3xl font-bold">Acceso de emergencia</h1>
                <p class="text-(--g-ink-soft)">Escribe el código de 6 dígitos de tu aplicación de autenticación.</p>
            </div>

            @error('emergency')
                <p role="alert" class="rounded-(--g-r-md) bg-(--g-crit-bg) px-4 py-3 text-sm font-semibold text-(--g-crit)">{{ $message }}</p>
            @enderror

            <form method="POST" action="{{ route('emergency.verify') }}" class="flex flex-col gap-5">
                @csrf
                <div class="flex flex-col gap-1.5">
                    <label for="code" class="text-sm font-semibold">Código de verificación</label>
                    <input id="code" type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required
                           class="g-input h-12 w-full text-xl tracking-[0.3em] tabular-nums">
                </div>
                <button type="submit" class="g-btn g-btn--ink h-12 w-full font-semibold">Entrar</button>
            </form>
        </section>
    </main>
</x-layouts.app>
