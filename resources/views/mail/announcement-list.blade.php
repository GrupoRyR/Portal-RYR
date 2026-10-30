{{--
    An announcement emailed to a company's all-staff list
    (App\Announcements\AnnouncementListEmails), in the company's brand
    (App\Branding\MailBrand). Laravel renders it as Markdown, so the user's
    text sits on one line inside an HTML block: CommonMark keeps it as it is
    (escaped here, with <br> for its line breaks) and never reads it as
    Markdown; the title and the company name come without line breaks
    (App\Announcements\AnnouncementListEmails::oneLine()), since a blank
    line would end the block. No line may start with four spaces (that is
    a code block).
--}}
<x-mail::message :brand="$brand">
<h1 style="color: {{ $brand->ink }};">{{ $title }}</h1>
@if ($imageUrl)
<p><img src="{{ $imageUrl }}" alt="" width="520" style="display: block; width: 100%; max-width: 520px; height: auto; border: 0; border-radius: 12px;"></p>
@endif
<p>{!! $body !!}</p>
<x-mail::button :url="$url" :brand="$brand">
Ver en el portal
</x-mail::button>
<x-slot:subcopy>
<p>Este anuncio se publicó en el portal para {{ $companyName }} y llega a la lista {{ $list }}. Si el botón «Ver en el portal» no funciona, copia y pega esta dirección en tu navegador: <a href="{{ $url }}" style="color: {{ $brand->ink }};">{{ $url }}</a></p>
</x-slot:subcopy>
</x-mail::message>
