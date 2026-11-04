<?php

namespace Tests\Feature\Layouts;

use DOMDocument;
use DOMXPath;
use Illuminate\Testing\TestResponse;

/**
 * Checks shared by the secondary pages restyled in Liquid Glass
 * (odd/tasks/liquid-glass.md G9, resources/css/glass-pages.css): the
 * company photo behind (.g-canvas), one .g-page column as the main
 * landmark, its title on glass and no temporary .g-sheet left.
 */
trait InspectsGlassPages
{
    protected function xpathOf(TestResponse $response): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$response->getContent());

        return new DOMXPath($dom);
    }

    /**
     * XPath predicate: the element's class list holds this exact class.
     */
    protected static function hasClass(string $class): string
    {
        return 'contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")';
    }

    protected function assertGlassPage(DOMXPath $page, string $label = ''): void
    {
        $this->assertSame(1, $page->query('//body['.self::hasClass('g-canvas').']')->length, $label.': the body is a glass canvas');
        $this->assertSame(1, $page->query('//main[@id="contenido"]['.self::hasClass('g-page').']')->length, $label.': main#contenido is the .g-page column');
        $this->assertSame(0, $page->query('//*['.self::hasClass('g-sheet').']')->length, $label.': no temporary .g-sheet');
        $this->assertSame(0, $page->query('//*['.self::hasClass('brand-band').']')->length, $label.': no brand band');
        // The page title sits on glass, never straight on the photo.
        $this->assertSame(1, $page->query('//main//h1')->length, $label.': one h1');
        $this->assertSame(1, $page->query('//main//*['.self::hasClass('g-banner').' or '.self::hasClass('g-card').']//h1')->length, $label.': the h1 is on glass');
    }

    /**
     * Visible text of the main column, whitespace collapsed.
     */
    protected function mainText(DOMXPath $page): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $page->query('//main')->item(0)?->textContent ?? ''));
    }
}
