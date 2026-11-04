<?php

namespace Tests\Unit\News;

use App\News\FeedFailure;
use App\News\FeedParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FeedParserTest extends TestCase
{
    private function parse(string $xml, int $limit = 100): array
    {
        return (new FeedParser)->parse($xml, $limit);
    }

    private function assertRefused(string $xml, string $code): void
    {
        try {
            $this->parse($xml);
            $this->fail("The feed was accepted; expected «{$code}».");
        } catch (FeedFailure $failure) {
            $this->assertSame($code, $failure->errorCode);
        }
    }

    public function test_rss_items_give_title_link_date_and_guid(): void
    {
        $entries = $this->parse(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/">
              <channel>
                <title>Economía</title>
                <link>https://www.example.com/</link>
                <item>
                  <title>  El dólar   baja
                  en Colombia </title>
                  <link>https://www.example.com/dolar</link>
                  <guid isPermaLink="false">abc-123</guid>
                  <pubDate>Mon, 02 Nov 2026 14:30:00 -0500</pubDate>
                  <description>&lt;p&gt;Never stored&lt;/p&gt;</description>
                </item>
                <item>
                  <title>Sin fecha ni guid</title>
                  <link>https://www.example.com/otra</link>
                  <dc:date>2026-11-01T08:00:00Z</dc:date>
                </item>
              </channel>
            </rss>
            XML);

        $this->assertCount(2, $entries);
        $this->assertSame('El dólar baja en Colombia', $entries[0]->title);
        $this->assertSame('https://www.example.com/dolar', $entries[0]->link);
        $this->assertSame('abc-123', $entries[0]->guid);
        $this->assertSame('2026-11-02T19:30:00+00:00', $entries[0]->publishedAt?->utc()->toIso8601String());
        $this->assertSame('https://www.example.com/otra', $entries[1]->guid, 'Without a guid the link identifies the item.');
        $this->assertSame('2026-11-01T08:00:00+00:00', $entries[1]->publishedAt?->utc()->toIso8601String());
        $this->assertFalse(property_exists($entries[0], 'description'));
    }

    public function test_atom_entries_use_the_alternate_link_and_the_id(): void
    {
        $entries = $this->parse(<<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <feed xmlns="http://www.w3.org/2005/Atom">
              <title>Sector</title>
              <entry>
                <title type="html">Nueva &lt;b&gt;norma&lt;/b&gt; de seguridad</title>
                <link rel="self" href="https://www.example.com/feed/1"/>
                <link rel="alternate" type="text/html" href="https://www.example.com/norma"/>
                <id>tag:example.com,2026:1</id>
                <published>2026-11-02T10:00:00-05:00</published>
                <updated>2026-11-03T10:00:00-05:00</updated>
                <content type="html">&lt;script&gt;alert(1)&lt;/script&gt;</content>
              </entry>
              <entry>
                <title>Sin rel</title>
                <link href="https://www.example.com/sin-rel"/>
                <id>tag:example.com,2026:2</id>
                <updated>2026-11-01T10:00:00Z</updated>
              </entry>
            </feed>
            XML);

        $this->assertCount(2, $entries);
        $this->assertSame('Nueva norma de seguridad', $entries[0]->title);
        $this->assertSame('https://www.example.com/norma', $entries[0]->link);
        $this->assertSame('tag:example.com,2026:1', $entries[0]->guid);
        $this->assertSame('2026-11-02T15:00:00+00:00', $entries[0]->publishedAt?->utc()->toIso8601String());
        $this->assertSame('https://www.example.com/sin-rel', $entries[1]->link);
        $this->assertSame('2026-11-01T10:00:00+00:00', $entries[1]->publishedAt?->utc()->toIso8601String());
    }

    public function test_script_and_html_in_titles_become_plain_text(): void
    {
        $entries = $this->parse(<<<'XML'
            <rss version="2.0"><channel>
              <item>
                <title>&lt;script&gt;alert("x")&lt;/script&gt;Alerta &lt;img src=x onerror=alert(1)&gt;&amp;amp; <![CDATA[<b>negrita</b> <style>p{}</style>]]></title>
                <link>https://www.example.com/a</link>
              </item>
              <item>
                <title>&lt;b&gt;&lt;/b&gt;   </title>
                <link>https://www.example.com/vacio</link>
              </item>
            </channel></rss>
            XML);

        $this->assertCount(1, $entries, 'A title that is empty once cleaned is skipped.');
        $this->assertSame('Alerta & negrita', $entries[0]->title);
        $this->assertStringNotContainsString('<', $entries[0]->title);
        $this->assertStringNotContainsString('alert', $entries[0]->title);
    }

    public function test_titles_are_cut_to_300_characters(): void
    {
        $long = str_repeat('ñ', 400);
        $entries = $this->parse("<rss version=\"2.0\"><channel><item><title>{$long}</title><link>https://www.example.com/a</link></item></channel></rss>");

        $this->assertSame(300, mb_strlen($entries[0]->title));
    }

    public function test_items_without_a_safe_https_link_are_skipped(): void
    {
        $entries = $this->parse(<<<'XML'
            <rss version="2.0"><channel>
              <item><title>Http</title><link>http://www.example.com/a</link></item>
              <item><title>Javascript</title><link>javascript:alert(1)</link></item>
              <item><title>Relativo</title><link>/noticia</link></item>
              <item><title>Sin enlace</title></item>
              <item><title>Bien</title><link> https://www.example.com/bien </link></item>
            </channel></rss>
            XML);

        $this->assertSame(['Bien'], array_map(fn ($entry) => $entry->title, $entries));
        $this->assertSame('https://www.example.com/bien', $entries[0]->link);
    }

    public function test_unparseable_dates_are_null(): void
    {
        $entries = $this->parse('<rss version="2.0"><channel><item><title>A</title><link>https://www.example.com/a</link><pubDate>ayer en la tarde</pubDate></item></channel></rss>');

        $this->assertNull($entries[0]->publishedAt);
    }

    public function test_only_the_first_entries_up_to_the_limit_are_read(): void
    {
        $items = '';
        for ($i = 1; $i <= 10; $i++) {
            $items .= "<item><title>N{$i}</title><link>https://www.example.com/{$i}</link></item>";
        }

        $this->assertCount(4, $this->parse("<rss version=\"2.0\"><channel>{$items}</channel></rss>", 4));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function doctypePayloads(): array
    {
        return [
            'external entity (XXE)' => [<<<'XML'
                <?xml version="1.0"?>
                <!DOCTYPE rss [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
                <rss version="2.0"><channel><item><title>&xxe;</title><link>https://www.example.com/a</link></item></channel></rss>
                XML],
            'billion laughs' => [<<<'XML'
                <?xml version="1.0"?>
                <!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;">]>
                <rss version="2.0"><channel><item><title>&lol2;</title><link>https://www.example.com/a</link></item></channel></rss>
                XML],
            'external dtd' => ['<?xml version="1.0"?><!DOCTYPE rss SYSTEM "https://evil.example.com/rss.dtd"><rss version="2.0"><channel/></rss>'],
            'lowercase doctype' => ['<!doctype rss><rss version="2.0"><channel/></rss>'],
            'entity without doctype keyword' => ['<rss version="2.0"><!ENTITY x "y"><channel/></rss>'],
        ];
    }

    #[DataProvider('doctypePayloads')]
    public function test_any_doctype_or_entity_declaration_is_refused(string $xml): void
    {
        $this->assertRefused($xml, 'doctype');
    }

    public function test_utf16_documents_are_refused_so_a_doctype_cannot_hide(): void
    {
        $xml = mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE rss [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss version="2.0"><channel/></rss>', 'UTF-16LE', 'UTF-8');

        $this->assertRefused("\xFF\xFE".$xml, 'invalid_xml');
        $this->assertRefused($xml, 'invalid_xml');
    }

    public function test_broken_xml_and_other_formats_are_refused(): void
    {
        $this->assertRefused('<rss version="2.0"><channel><item>', 'invalid_xml');
        $this->assertRefused('', 'invalid_xml');
        // A web page has its own code (odd/tasks/observaciones.md O3).
        $this->assertRefused('<html><body>Not a feed</body></html>', 'html_page');
        $this->assertRefused('<feed><entry/></feed>', 'unsupported_format');
        $this->assertRefused('{"items": []}', 'invalid_xml');
    }

    // --- Review fixes (odd/tasks/noticias.md, R1) ---------------------------

    private function dateOf(string $date): ?string
    {
        $entries = $this->parse('<rss version="2.0"><channel><item><title>A</title><link>https://www.example.com/a</link><pubDate>'.$date.'</pubDate></item></channel></rss>');

        return $entries[0]->publishedAt?->utc()->toIso8601String();
    }

    public function test_a_wrong_weekday_never_moves_the_date(): void
    {
        // 2 Nov 2026 is a Monday; PHP would read «Fri» as the next Friday.
        $this->assertSame('2026-11-02T19:30:00+00:00', $this->dateOf('Fri, 02 Nov 2026 14:30:00 -0500'));
        $this->assertSame('2026-11-02T19:30:00+00:00', $this->dateOf('Mon, 02 Nov 2026 14:30:00 -0500'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function zoneSpellings(): array
    {
        return [
            'UT' => ['Mon, 02 Nov 2026 14:30:00 UT', '2026-11-02T14:30:00+00:00'],
            'GMT' => ['Mon, 02 Nov 2026 14:30:00 GMT', '2026-11-02T14:30:00+00:00'],
            'Z' => ['Mon, 02 Nov 2026 14:30:00 Z', '2026-11-02T14:30:00+00:00'],
            'COT' => ['Mon, 02 Nov 2026 14:30:00 COT', '2026-11-02T19:30:00+00:00'],
            'EST' => ['Mon, 02 Nov 2026 14:30:00 EST', '2026-11-02T19:30:00+00:00'],
            'short negative offset' => ['Mon, 02 Nov 2026 14:30:00 -05', '2026-11-02T19:30:00+00:00'],
            'short positive offset' => ['Mon, 02 Nov 2026 14:30:00 +01', '2026-11-02T13:30:00+00:00'],
            'offset with colon' => ['Mon, 02 Nov 2026 14:30:00 -05:00', '2026-11-02T19:30:00+00:00'],
            'two-digit year' => ['02 Nov 26 14:30 +0000', '2026-11-02T14:30:00+00:00'],
            'iso short offset' => ['2026-11-02T14:30:00-05', '2026-11-02T19:30:00+00:00'],
            'iso z' => ['2026-11-02T14:30:00.123Z', '2026-11-02T14:30:00+00:00'],
        ];
    }

    #[DataProvider('zoneSpellings')]
    public function test_time_zone_spellings_are_understood(string $date, string $expected): void
    {
        $this->assertSame($expected, $this->dateOf($date));
    }

    public function test_dates_without_an_offset_are_bogota_time(): void
    {
        $this->assertSame('2026-11-02T19:30:00+00:00', $this->dateOf('2026-11-02T14:30:00'));
        $this->assertSame('2026-11-02T19:30:00+00:00', $this->dateOf('02 Nov 2026 14:30'));
        $this->assertSame('2026-11-02T05:00:00+00:00', $this->dateOf('2026-11-02'));
    }

    public function test_unknown_zones_and_impossible_dates_give_no_date(): void
    {
        $this->assertNull($this->dateOf('Mon, 02 Nov 2026 14:30:00 XYZ'));
        $this->assertNull($this->dateOf('31 Feb 2026 10:00 +0000'));
        $this->assertNull($this->dateOf('2026-13-01'));
        $this->assertNull($this->dateOf('2026-11-02T25:00:00Z'));
    }

    public function test_a_less_than_sign_in_a_title_is_kept(): void
    {
        $entries = $this->parse('<rss version="2.0"><channel>'
            .'<item><title>Tasa &lt;5% en 2027</title><link>https://www.example.com/a</link></item>'
            .'<item><title>Tasa &amp;lt;5% &amp;amp; IPC &lt;b&gt;alto&lt;/b&gt;</title><link>https://www.example.com/b</link></item>'
            .'</channel></rss>');

        $this->assertSame('Tasa <5% en 2027', $entries[0]->title);
        $this->assertSame('Tasa <5% & IPC alto', $entries[1]->title);
    }

    public function test_relative_links_resolve_against_the_feed_url(): void
    {
        $entries = (new FeedParser)->parse(<<<'XML'
            <rss version="2.0"><channel>
              <item><title>Raíz</title><link>/noticia/1</link></item>
              <item><title>Hermana</title><link>otra?x=1</link></item>
              <item><title>Arriba</title><link>../arriba</link></item>
              <item><title>Sin esquema</title><link>//cdn.example.com/a</link></item>
              <item><title>Absoluta</title><link>https://www.example.org/b</link></item>
            </channel></rss>
            XML, 100, 'https://news.example.com/feeds/rss.xml?page=1');

        $this->assertSame([
            'https://news.example.com/noticia/1',
            'https://news.example.com/feeds/otra?x=1',
            'https://news.example.com/arriba',
            'https://cdn.example.com/a',
            'https://www.example.org/b',
        ], array_map(fn ($entry) => $entry->link, $entries));
    }

    public function test_atom_xml_base_is_applied_and_must_stay_https(): void
    {
        $entries = (new FeedParser)->parse(<<<'XML'
            <feed xmlns="http://www.w3.org/2005/Atom" xml:base="https://www.example.com/blog/">
              <entry><title>Uno</title><link href="post/1"/><id>1</id></entry>
              <entry xml:base="sector/"><title>Dos</title><link href="post/2"/><id>2</id></entry>
              <entry xml:base="http://insecure.example.com/"><title>Insegura</title><link href="post/3"/><id>3</id></entry>
            </feed>
            XML, 100, 'https://news.example.com/atom.xml');

        $this->assertSame(
            ['https://www.example.com/blog/post/1', 'https://www.example.com/blog/sector/post/2'],
            array_map(fn ($entry) => $entry->link, $entries),
        );
    }

    public function test_a_feed_without_usable_entries_is_refused(): void
    {
        $this->assertRefused('<rss version="2.0"><channel><item><title>Http</title><link>http://www.example.com/a</link></item><item><title></title><link>https://www.example.com/b</link></item></channel></rss>', 'no_entries');
        $this->assertRefused('<rss version="2.0"><channel><item><title>Relativa</title><link>/a</link></item></channel></rss>', 'no_entries');
        $this->assertRefused('<rss version="2.0"><channel/></rss>', 'no_entries');
        $this->assertRefused('<feed xmlns="http://www.w3.org/2005/Atom"/>', 'no_entries');
    }
}
