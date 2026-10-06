{{-- Emergency sign-in, step 1; not linked from anywhere. --}}
<x-layouts.app title="Acceso de emergencia" noindex>
    <main class="mx-auto flex min-h-dvh w-full max-w-md flex-col justify-center gap-8 px-6 py-12">
        <div class="flex flex-col gap-2">
            <h1 class="text-3xl font-bold">Acceso de emergencia</h1>
            <p class="text-fg-soft">Solo para cuando el inicio de sesión con Microsoft no está disponible.</p>
        </div>

        @error('emergency')
            <p role="alert" class="rounded-control bg-status-failure-bg px-4 py-3 text-sm font-semibold text-status-failure">{{ $message }}</p>
        @enderror

        <form method="POST" action="{{ route('emergency.credentials') }}" class="flex flex-col gap-5">
            @csrf
            <div class="flex flex-col gap-1.5">
                <label for="email" class="text-sm font-semibold">Correo</label>
                <input id="email" type="email" name="email" autocomplete="username" required
                       class="h-11 rounded-[10px] border border-line-strong bg-surface px-3 text-[0.9375rem]">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="password" class="text-sm font-semibold">Contraseña</label>
                <input id="password" type="password" name="password" autocomplete="current-password" required
                       class="h-11 rounded-[10px] border border-line-strong bg-surface px-3 text-[0.9375rem]">
            </div>
            <button type="submit" class="h-12 rounded-control bg-fg font-semibold text-on-fg hover:opacity-90">Continuar</button>
        </form>
    </main>
</x-layouts.app>
