{{-- Emergency sign-in, step 2 (authenticator code). --}}
<x-layouts.app title="Acceso de emergencia" noindex>
    <main class="mx-auto flex min-h-dvh w-full max-w-md flex-col justify-center gap-8 px-6 py-12">
        <div class="flex flex-col gap-2">
            <h1 class="text-3xl font-bold">Acceso de emergencia</h1>
            <p class="text-fg-soft">Escribe el código de 6 dígitos de tu aplicación de autenticación.</p>
        </div>

        @error('emergency')
            <p role="alert" class="rounded-control bg-status-failure-bg px-4 py-3 text-sm font-semibold text-status-failure">{{ $message }}</p>
        @enderror

        <form method="POST" action="{{ route('emergency.verify') }}" class="flex flex-col gap-5">
            @csrf
            <div class="flex flex-col gap-1.5">
                <label for="code" class="text-sm font-semibold">Código de verificación</label>
                <input id="code" type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required
                       class="h-12 rounded-[10px] border border-line-strong bg-surface px-3 text-xl tracking-[0.3em] tabular-nums">
            </div>
            <button type="submit" class="h-12 rounded-control bg-fg font-semibold text-on-fg hover:opacity-90">Entrar</button>
        </form>
    </main>
</x-layouts.app>
