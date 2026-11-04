{{--
    An event emailed to a company's all-staff list (App\Calendar\EventListEmails),
    in the company's brand (App\Branding\MailBrand). Laravel renders it as
    Markdown, so every text of a person sits on one line inside an HTML
    block, escaped here (the description with <br> for its line breaks);
    the title, the date and the place come without line breaks, since a
    blank line would end the block. No line may start with four spaces
    (that is a code block). Same rules as mail/announcement-list.
--}}
<x-mail::message :brand="$brand">
<h1 style="color: {{ $brand->ink }};">{{ $title }}</h1>
<p><strong>{{ $when }}</strong>@if ($location)<br>{{ $location }}@endif</p>
@if ($description)
<p>{!! $description !!}</p>
@endif
<x-mail::button :url="$url" :brand="$brand">
Ver en el portal
</x-mail::button>
<x-slot:subcopy>
<p>Este evento se publicó en el portal para {{ $companyName }} y llega a la lista {{ $list }}. Si el botón «Ver en el portal» no funciona, copia y pega esta dirección en tu navegador: <a href="{{ $url }}" style="color: {{ $brand->ink }};">{{ $url }}</a></p>
</x-slot:subcopy>
</x-mail::message>
