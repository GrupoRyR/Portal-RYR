{{-- Laravel's notification email; `brand` (App\Branding\MailBrand) paints the company logo and colors. --}}
<x-mail::message :brand="$brand ?? null">
{{-- Greeting, in the brand ink (AA on white) when there is a brand --}}
@php($heading = ! empty($greeting) ? $greeting : ($level === 'error' ? __('Whoops!') : __('Hello!')))
@if ($brand ?? null)
<h1 style="color: {{ $brand->ink }};">{{ $heading }}</h1>
@else
# {{ $heading }}
@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Action Button --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color" :brand="$brand ?? null">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Salutation --}}
@if (! empty($salutation))
{{ $salutation }}
@else
@lang('Regards,')<br>
{{ config('app.name') }}
@endif

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
Si el botón «{{ $actionText }}» no funciona, copia y pega esta dirección en tu navegador: <span class="break-all">@if ($brand ?? null)<a href="{{ $actionUrl }}" style="color: {{ $brand->ink }};">{{ $displayableActionUrl }}</a>@else[{{ $displayableActionUrl }}]({{ $actionUrl }})@endif</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
