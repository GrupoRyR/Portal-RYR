{{-- Emergency sign-in, step 1; not linked from anywhere. --}}
<x-layouts.app title="Acceso de emergencia" noindex glass>
    <main class="mx-auto flex min-h-dvh w-full max-w-md flex-col justify-center px-4 py-12">
        <section class="g-card flex flex-col gap-8 px-8 py-9 max-sm:px-6">
            <div class="flex flex-col gap-2">
                <h1 class="text-3xl font-bold">Acceso de emergencia</h1>
                <p class="text-(--g-ink-soft)">Solo para cuando el inicio de sesión con Microsoft no está disponible.</p>
            </div>

            @error('emergency')
                <p role="alert" class="rounded-(--g-r-md) bg-(--g-crit-bg) px-4 py-3 text-sm font-semibold text-(--g-crit)">{{ $message }}</p>
            @enderror

            <form method="POST" action="{{ route('emergency.credentials') }}" class="flex flex-col gap-5">
                @csrf
                <div class="flex flex-col gap-1.5">
                    <label for="email" class="text-sm font-semibold">Correo</label>
                    <input id="email" type="email" name="email" autocomplete="username" required
                           class="g-input h-11 w-full text-[0.9375rem]">
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="password" class="text-sm font-semibold">Contraseña</label>
                    <input id="password" type="password" name="password" autocomplete="current-password" required
                           class="g-input h-11 w-full text-[0.9375rem]">
                </div>
                <button type="submit" class="g-btn g-btn--ink h-12 w-full font-semibold">Continuar</button>
            </form>
        </section>
    </main>
</x-layouts.app>
