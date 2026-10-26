{{--
    Mail header. With a brand (App\Branding\MailBrand) it is a band of the
    company dark color with the logo as an image: the dark logo straight on
    it, a light-only logo on a white pill (never inverted), or the name in
    white. Colors are inline because email clients ignore CSS variables.
--}}
@props(['url', 'brand' => null])
<tr>
<td class="header"@if ($brand) style="background-color: {{ $brand->dark }};"@endif>
<a href="{{ $url }}" style="display: inline-block;@if ($brand) color: #FFFFFF;@endif">
@if ($brand?->logoUrl && $brand->logoOnDark)
<img src="{{ $brand->logoUrl }}" alt="{{ $brand->name }}" height="28" style="height: 28px; width: auto; border: 0;">
@elseif ($brand?->logoUrl)
<span style="display: inline-block; background-color: #FFFFFF; border-radius: 999px; padding: 8px 16px;"><img src="{{ $brand->logoUrl }}" alt="{{ $brand->name }}" height="28" style="height: 28px; width: auto; border: 0;"></span>
@else
{!! $slot !!}
@endif
</a>
</td>
</tr>
