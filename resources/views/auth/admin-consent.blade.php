{{-- Where Microsoft sends a tenant administrator after the admin-consent link. --}}
<x-layouts.app :title="$granted ? 'Consentimiento registrado' : 'Consentimiento no completado'" noindex class="bg-[#FAFAF8] dark:bg-ground">
    <main class="mx-auto flex min-h-dvh w-full max-w-md flex-col justify-center gap-4 px-7 py-12">
        @if ($granted)
            <h1 class="text-[2rem] leading-tight font-bold">Consentimiento registrado</h1>
            <p class="text-base leading-normal text-fg-soft">Gracias. Microsoft confirmó la autorización de tu organización para el Portal RYR. El administrador del portal lo verá en unos minutos y activará el acceso. Ya puedes cerrar esta ventana.</p>
        @else
            <h1 class="text-[2rem] leading-tight font-bold">No se completó el consentimiento</h1>
            <p class="text-base leading-normal text-fg-soft">Microsoft no registró la autorización. Si fue un error, abre de nuevo el enlace que te enviaron e inicia sesión con una cuenta de administrador de Microsoft 365.</p>
        @endif
    </main>
</x-layouts.app>
