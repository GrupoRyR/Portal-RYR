<?php

namespace Tests\Unit\Catalog;

use DOMDocument;
use DOMElement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AppIconFilesTest extends TestCase
{
    public const SLUGS = ['toma-pedidos', 'portafolio', 'cuentas-por-pagar', 'cartera', 'camaras'];

    /**
     * @return array<string, array{string}>
     */
    public static function iconFiles(): array
    {
        $files = [];

        foreach (self::SLUGS as $slug) {
            $files[$slug] = ["{$slug}.svg"];
            $files["{$slug} (dark)"] = ["{$slug}-dark.svg"];
        }

        return $files;
    }

    #[DataProvider('iconFiles')]
    public function test_each_icon_is_a_safe_48px_svg_tile(string $file): void
    {
        $path = dirname(__DIR__, 3).'/public/icons/apps/'.$file;
        $this->assertFileExists($path);

        $document = new DOMDocument;
        $this->assertTrue(@$document->loadXML((string) file_get_contents($path), LIBXML_NONET), "{$file} is not well-formed XML.");

        $root = $document->documentElement;
        $this->assertSame('svg', $root->localName);
        $this->assertSame('http://www.w3.org/2000/svg', $root->namespaceURI);
        $this->assertSame('0 0 48 48', $root->getAttribute('viewBox'));

        foreach ($document->getElementsByTagName('*') as $element) {
            /** @var DOMElement $element */
            $this->assertNotContains(strtolower($element->localName), ['script', 'foreignobject', 'iframe', 'use', 'image'], "{$file} contains <{$element->localName}>.");

            foreach ($element->attributes as $attribute) {
                $this->assertStringStartsNotWith('on', strtolower($attribute->name), "{$file} has an event handler.");
                $this->assertStringNotContainsStringIgnoringCase('javascript:', $attribute->value);
                $this->assertStringNotContainsString('href', strtolower($attribute->name), "{$file} links to another resource.");
            }
        }

        $tile = $document->getElementsByTagName('rect')->item(0);
        $this->assertSame('14', $tile?->getAttribute('rx'), "{$file} has no 14 px rounded tile.");
    }
}
