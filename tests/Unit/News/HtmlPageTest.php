<?php

namespace Tests\Unit\News;

use App\News\FeedDiscovery;
use App\News\FeedFailure;
use App\News\FeedParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A web page given instead of a feed (odd/tasks/observaciones.md O3): it is
 * told apart from an unsafe XML document, and the feed it announces with
 * `<link rel="alternate">` is found without loading anything.
 */
class HtmlPageTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function htmlPages(): array
    {
        return [
            'html5 doctype' => ['<!DOCTYPE html><html lang="es"><head><title>Bogotá</title></head><body></body></html>'],
            'lowercase doctype' => ["<!doctype html>\n<html><head></head></html>"],
            'old html doctype' => ['<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd"><html></html>'],
            'bom and whitespace' => ["\xEF\xBB\xBF \r\n\t<!DOCTYPE html><html></html>"],
            'no doctype' => ['<html><head><title>x</title></head></html>'],
            'comment first' => ["<!-- generado -->\n<!DOCTYPE html><html></html>"],
        ];
    }

    #[DataProvider('htmlPages')]
    public function test_a_web_page_is_refused_as_html_page(string $html): void
    {
        try {
            (new FeedParser)->parse($html);
            $this->fail('The page was accepted.');
        } catch (FeedFailure $failure) {
            $this->assertSame(FeedFailure::HTML_PAGE, $failure->errorCode);
        }
    }

    public function test_an_xml_doctype_is_still_refused_as_unsafe(): void
    {
        try {
            (new FeedParser)->parse('<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss version="2.0"><channel/></rss>');
            $this->fail('The document was accepted.');
        } catch (FeedFailure $failure) {
            $this->assertSame(FeedFailure::DOCTYPE, $failure->errorCode);
        }
    }

    public function test_an_html_doctype_after_the_xml_declaration_is_still_unsafe_xml(): void
    {
        try {
            (new FeedParser)->parse('<?xml version="1.0"?><!DOCTYPE html [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss version="2.0"><channel/></rss>');
            $this->fail('The document was accepted.');
        } catch (FeedFailure $failure) {
            $this->assertSame(FeedFailure::DOCTYPE, $failure->errorCode);
        }
    }

    public function test_rss_and_atom_alternate_links_are_found_and_resolved(): void
    {
        $html = <<<'HTML'
            <!DOCTYPE html>
            <html><head>
            <meta charset="utf-8">
            <link rel="stylesheet" href="/estilos.css">
            <link rel="alternate" type="application/rss+xml" title="Bogotá" href="/rss/bogota.xml">
            <LINK HREF='https://www.eltiempo.com/atom.xml' TYPE='application/atom+xml' REL='Alternate'>
            <link rel="alternate" hreflang="en" href="https://www.eltiempo.com/en">
            <link rel="alternate" type="application/rss+xml" href="rss/relativo.xml?a=1&amp;b=2">
            </head><body><link rel="alternate" type="application/rss+xml" href="/en-el-cuerpo.xml"></body></html>
            HTML;

        $this->assertSame([
            'https://www.eltiempo.com/rss/bogota.xml',
            'https://www.eltiempo.com/atom.xml',
            'https://www.eltiempo.com/seccion/rss/relativo.xml?a=1&b=2',
        ], FeedDiscovery::candidates($html, 'https://www.eltiempo.com/seccion/bogota'));
    }

    public function test_only_safe_https_links_are_kept_once(): void
    {
        $html = '<html><head>'
            .'<link rel="alternate" type="application/rss+xml" href="http://www.eltiempo.com/rss.xml">'
            .'<link rel="alternate" type="application/rss+xml" href="https://10.0.0.5/rss.xml">'
            .'<link rel="alternate" type="application/rss+xml" href="https://user:pw@www.eltiempo.com/rss.xml">'
            .'<link rel="alternate" type="application/rss+xml" href="javascript:alert(1)">'
            .'<link rel="alternate" type="application/rss+xml" href="https://www.eltiempo.com:8443/rss.xml">'
            .'<link rel="alternate" type="application/rss+xml" href="https://www.eltiempo.com/ok.xml">'
            .'<link rel="alternate" type="application/rss+xml" href="https://www.eltiempo.com/ok.xml">'
            .'</head></html>';

        $this->assertSame(['https://www.eltiempo.com/ok.xml'], FeedDiscovery::candidates($html, 'https://www.eltiempo.com/'));
    }

    public function test_only_the_first_bytes_of_the_page_are_read(): void
    {
        $html = '<html><head>'.str_repeat('<meta name="x" content="y">', 20000)
            .'<link rel="alternate" type="application/rss+xml" href="/tarde.xml"></head></html>';

        $this->assertGreaterThan(FeedDiscovery::MAX_BYTES, strlen($html));
        $this->assertSame([], FeedDiscovery::candidates($html, 'https://www.eltiempo.com/'));
    }

    public function test_a_page_without_a_feed_link_gives_nothing(): void
    {
        $this->assertSame([], FeedDiscovery::candidates('<!DOCTYPE html><html><head><title>x</title></head></html>', 'https://www.eltiempo.com/bogota'));
    }
}
