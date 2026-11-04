{{-- «Asistente» in the user menu of components/portal-header (App\View\Components\AssistantLink): only rendered while the assistant is on. --}}
<a href="{{ route('assistant.show') }}" @if (request()->routeIs('assistant.*')) aria-current="page" @endif {{ $attributes->class(['flex min-h-touch items-center gap-3 rounded-[14px] px-3 text-sm font-semibold hover:bg-(--g-hover-row)']) }}>
    <x-home.icon name="chat" class="size-[18px] shrink-0 text-(--g-ink-faint)" />Asistente
</a>
