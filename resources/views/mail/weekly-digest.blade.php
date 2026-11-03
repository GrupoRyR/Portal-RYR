{{--
    The Monday digest of a company's all-staff list (App\Digest\WeeklyDigests),
    in the company's brand (App\Branding\MailBrand). Laravel renders it as
    Markdown, so everything sits in HTML blocks with no blank line between
    them: CommonMark keeps them as they are and never reads a title as
    Markdown. Every text is escaped here and comes on one line
    (App\Announcements\AnnouncementListEmails::oneLine()). No line may start
    with four spaces (that is a code block).
--}}
<x-mail::message :brand="$brand">
<h1 style="color: {{ $brand->ink }};">Resumen de la semana</h1>
<p>Lo que pasa en {{ $companyName }} {{ $range }}.</p>
@if ($announcements !== [])
<h2 style="color: {{ $brand->ink }}; font-size: 16px; margin-top: 24px;">Anuncios</h2>
<ul style="padding-left: 20px;">
@foreach ($announcements as $announcement)
<li style="margin-bottom: 8px;"><a href="{{ $announcement['url'] }}" style="color: {{ $brand->ink }};">{{ $announcement['title'] }}</a> · {{ $announcement['date'] }}@if ($announcement['mandatory']) · <strong>Lectura obligatoria</strong>@endif</li>
@endforeach
</ul>
@endif
@if ($dates !== [])
<h2 style="color: {{ $brand->ink }}; font-size: 16px; margin-top: 24px;">Fechas de la semana</h2>
<ul style="padding-left: 20px;">
@foreach ($dates as $date)
<li style="margin-bottom: 8px;"><strong>{{ $date['when'] }}</strong> · {{ $date['kind'] }}: {{ $date['title'] }}</li>
@endforeach
</ul>
@endif
@if ($celebrations !== [])
<h2 style="color: {{ $brand->ink }}; font-size: 16px; margin-top: 24px;">Cumpleaños y aniversarios</h2>
<ul style="padding-left: 20px;">
@foreach ($celebrations as $celebration)
<li style="margin-bottom: 8px;"><strong>{{ $celebration['when'] }}</strong> · {{ $celebration['name'] }}: {{ $celebration['label'] }}</li>
@endforeach
</ul>
@endif
<x-mail::button :url="$url" :brand="$brand">
Abrir el portal
</x-mail::button>
<x-slot:subcopy>
<p>Este resumen llega cada lunes a la lista {{ $list }} con lo que todo el personal de {{ $companyName }} ve en el portal. Si el botón «Abrir el portal» no funciona, copia y pega esta dirección en tu navegador: <a href="{{ $url }}" style="color: {{ $brand->ink }};">{{ $url }}</a></p>
</x-slot:subcopy>
</x-mail::message>
