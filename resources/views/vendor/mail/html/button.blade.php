{{-- Laravel's mail button; with a brand (App\Branding\MailBrand) the primary one takes the company button color (white text on it meets AA). --}}
@props([
    'url',
    'color' => 'primary',
    'align' => 'center',
    'brand' => null,
])
<table class="action" align="{{ $align }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td>
<a href="{{ $url }}" class="button button-{{ $color }}" target="_blank" rel="noopener"@if ($brand && $color === 'primary') style="background-color: {{ $brand->button }}; border-color: {{ $brand->button }};"@endif>{!! $slot !!}</a>
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
</table>
