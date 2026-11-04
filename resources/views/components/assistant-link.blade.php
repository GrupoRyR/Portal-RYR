{{-- «Asistente» (App\View\Components\AssistantLink): only rendered while the assistant is on. --}}
@if ($variant === 'menu')
    <a href="{{ route('assistant.show') }}" @if (request()->routeIs('assistant.*')) aria-current="page" @endif class="flex min-h-touch items-center gap-3 rounded-control px-3 text-sm font-semibold hover:bg-subtle">
        <x-home.icon name="chat" class="size-[18px] text-fg-muted" />Asistente
    </a>
@else
    <a href="{{ route('assistant.show') }}" @if (request()->routeIs('assistant.*')) aria-current="page" @endif {{ $attributes->class(['inline-flex min-h-touch shrink-0 items-center gap-2 rounded-control border border-white/40 px-4 text-sm font-semibold hover:bg-white/10']) }}>
        <x-home.icon name="chat" class="size-[18px]" />Asistente
    </a>
@endif
