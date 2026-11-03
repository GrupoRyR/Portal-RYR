<?php

namespace Tests\Feature\News;

use App\News\FeedFailure;
use App\News\FeedFetcher;
use App\News\HostResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FeedFetcherTest extends TestCase
{
    /** @var array<string, list<string>> */
    private array $dns = [];

    /** @var list<string> */
    private array $lookups = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->dns = [
            'news.example.com' => ['93.184.216.34'],
            'cdn.example.net' => ['2606:2800:220:1:248:1893:25c8:1946'],
        ];

        $test = $this;
        $this->app->instance(HostResolver::class, new class($test) implements HostResolver
        {
            public function __construct(private FeedFetcherTest $test) {}

            public function resolve(string $host): array
            {
                return $this->test->lookup($host);
            }
        });
    }

    /**
     * The fake DNS: records every lookup, never touches the network.
     *
     * @return list<string>
     */
    public function lookup(string $host): array
    {
        $this->lookups[] = $host;

        return $this->dns[$host] ?? [];
    }

    private function fetcher(): FeedFetcher
    {
        return $this->app->make(FeedFetcher::class);
    }

    private function assertFails(string $url, string $code): void
    {
        try {
            $this->fetcher()->fetch($url);
            $this->fail("The fetch succeeded; expected «{$code}».");
        } catch (FeedFailure $failure) {
            $this->assertSame($code, $failure->errorCode);
        }
    }

    public function test_a_feed_is_fetched_from_the_checked_address_without_redirects_or_proxy(): void
    {
        $seen = [];
        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen = $options;

            return Http::response('<rss version="2.0"><channel/></rss>', 200, ['Content-Type' => 'application/rss+xml']);
        });

        $this->assertSame('<rss version="2.0"><channel/></rss>', $this->fetcher()->fetch('https://news.example.com/rss'));

        $this->assertSame(['news.example.com:443:93.184.216.34'], $seen['curl'][CURLOPT_RESOLVE]);
        $this->assertTrue($seen['curl'][CURLOPT_FRESH_CONNECT]);
        $this->assertTrue($seen['curl'][CURLOPT_FORBID_REUSE]);
        $this->assertFalse($seen['allow_redirects']);
        $this->assertSame(['no' => ['*']], $seen['proxy']);
        $this->assertEquals(10, $seen['timeout']);
        $this->assertIsCallable($seen['progress']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://news.example.com/rss'
            && str_contains($request->header('User-Agent')[0] ?? '', 'PortalRYR'));
    }

    // --- Review fixes (odd/tasks/noticias.md, R1) ---------------------------

    public function test_the_client_asks_for_an_uncompressed_body_and_never_decodes_it_itself(): void
    {
        $seen = [];
        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen = $options;

            return Http::response('<rss/>');
        });

        $this->fetcher()->fetch('https://news.example.com/rss');

        $this->assertFalse($seen['decode_content']);
        Http::assertSent(fn (Request $request) => $request->header('Accept-Encoding') === ['identity']);
    }

    public function test_gzip_and_deflate_bodies_are_decoded_here(): void
    {
        Http::fake([
            'https://news.example.com/gzip' => Http::response(gzencode('<rss>gzip</rss>'), 200, ['Content-Encoding' => 'gzip']),
            'https://news.example.com/deflate' => Http::response(gzcompress('<rss>deflate</rss>'), 200, ['Content-Encoding' => 'deflate']),
            'https://news.example.com/raw-deflate' => Http::response(gzdeflate('<rss>raw</rss>'), 200, ['Content-Encoding' => 'deflate']),
            'https://news.example.com/identity' => Http::response('<rss>plain</rss>', 200, ['Content-Encoding' => 'identity']),
        ]);

        $this->assertSame('<rss>gzip</rss>', $this->fetcher()->fetch('https://news.example.com/gzip'));
        $this->assertSame('<rss>deflate</rss>', $this->fetcher()->fetch('https://news.example.com/deflate'));
        $this->assertSame('<rss>raw</rss>', $this->fetcher()->fetch('https://news.example.com/raw-deflate'));
        $this->assertSame('<rss>plain</rss>', $this->fetcher()->fetch('https://news.example.com/identity'));
    }

    public function test_a_compressed_body_over_one_megabyte_once_decoded_is_refused(): void
    {
        $bomb = gzencode(str_repeat(' ', 20_000_000), 9);
        $this->assertLessThan(1_048_576, strlen($bomb));

        Http::fake([
            'https://news.example.com/bomb' => Http::response($bomb, 200, ['Content-Encoding' => 'gzip']),
            'https://news.example.com/limit' => Http::response(gzencode(str_repeat('a', 1_048_576)), 200, ['Content-Encoding' => 'gzip']),
            'https://news.example.com/over' => Http::response(gzencode(str_repeat('a', 1_048_577)), 200, ['Content-Encoding' => 'gzip']),
        ]);

        $this->assertFails('https://news.example.com/bomb', 'too_large');
        $this->assertFails('https://news.example.com/over', 'too_large');
        $this->assertSame(1_048_576, strlen($this->fetcher()->fetch('https://news.example.com/limit')));
    }

    public function test_unknown_or_broken_encodings_are_refused(): void
    {
        Http::fake([
            'https://news.example.com/br' => Http::response('brotli bytes', 200, ['Content-Encoding' => 'br']),
            'https://news.example.com/broken' => Http::response('not gzip at all', 200, ['Content-Encoding' => 'gzip']),
            'https://news.example.com/double' => Http::response(gzencode(gzencode('<rss/>')), 200, ['Content-Encoding' => 'gzip, gzip']),
        ]);

        $this->assertFails('https://news.example.com/br', 'unsupported_encoding');
        $this->assertFails('https://news.example.com/broken', 'unsupported_encoding');
        $this->assertFails('https://news.example.com/double', 'unsupported_encoding');
    }

    public function test_ipv6_addresses_are_pinned_in_brackets(): void
    {
        $seen = [];
        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen = $options;

            return Http::response('<rss/>');
        });

        $this->fetcher()->fetch('https://cdn.example.net/feed');

        $this->assertSame(['cdn.example.net:443:[2606:2800:220:1:248:1893:25c8:1946]'], $seen['curl'][CURLOPT_RESOLVE]);
    }

    public function test_the_progress_callback_aborts_past_one_megabyte(): void
    {
        $seen = [];
        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen = $options;

            return Http::response('<rss/>');
        });

        $this->fetcher()->fetch('https://news.example.com/rss');

        $this->assertFalse((bool) ($seen['progress'])(0, 1_048_576, 0, 0));
        $this->assertTrue((bool) ($seen['progress'])(0, 1_048_577, 0, 0));
        $this->assertTrue((bool) ($seen['progress'])(5_000_000, 10, 0, 0), 'A declared size over the limit aborts at once.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blockedResolutions(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'private' => ['10.1.2.3'],
            'link-local metadata' => ['169.254.169.254'],
            'cgnat' => ['100.64.1.1'],
            'multicast' => ['224.0.0.251'],
            'reserved' => ['240.0.0.1'],
            'ipv6 loopback' => ['::1'],
            'ipv4-mapped ipv6' => ['::ffff:10.0.0.1'],
            'ipv6 unique local' => ['fd12::1'],
        ];
    }

    #[DataProvider('blockedResolutions')]
    public function test_hosts_that_resolve_to_non_public_addresses_are_refused(string $ip): void
    {
        Http::fake();
        $this->dns['evil.example.org'] = [$ip];

        $this->assertFails('https://evil.example.org/rss', 'blocked_address');
        Http::assertNothingSent();
    }

    public function test_one_non_public_address_among_public_ones_refuses_the_host(): void
    {
        Http::fake();
        $this->dns['mixed.example.org'] = ['93.184.216.34', '192.168.0.10'];

        $this->assertFails('https://mixed.example.org/rss', 'blocked_address');
        Http::assertNothingSent();
    }

    public function test_hosts_that_do_not_resolve_are_refused(): void
    {
        Http::fake();

        $this->assertFails('https://unknown.example.org/rss', 'dns_failed');
        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidUrls(): array
    {
        return [
            'http' => ['http://news.example.com/rss'],
            'port' => ['https://news.example.com:8443/rss'],
            'userinfo' => ['https://me@news.example.com/rss'],
            'ip literal' => ['https://93.184.216.34/rss'],
            'ipv6 literal' => ['https://[::1]/rss'],
        ];
    }

    #[DataProvider('invalidUrls')]
    public function test_invalid_urls_are_refused_before_any_lookup(string $url): void
    {
        Http::fake();

        $this->assertFails($url, 'invalid_url');
        $this->assertSame([], $this->lookups);
        Http::assertNothingSent();
    }

    public function test_redirects_are_followed_up_to_three_times_each_checked_again(): void
    {
        $this->dns['b.example.com'] = ['93.184.216.35'];
        Http::fake([
            'https://news.example.com/rss' => Http::response('', 301, ['Location' => 'https://b.example.com/1']),
            'https://b.example.com/1' => Http::response('', 302, ['Location' => '/2']),
            'https://b.example.com/2' => Http::response('', 307, ['Location' => 'https://news.example.com/final']),
            'https://news.example.com/final' => Http::response('<rss/>'),
        ]);

        $this->assertSame('<rss/>', $this->fetcher()->fetch('https://news.example.com/rss'));
        $this->assertSame(['news.example.com', 'b.example.com', 'b.example.com', 'news.example.com'], $this->lookups);
        Http::assertSentCount(4);
    }

    public function test_a_fourth_redirect_is_refused(): void
    {
        Http::fake([
            'https://news.example.com/*' => Http::sequence()
                ->push('', 302, ['Location' => 'https://news.example.com/2'])
                ->push('', 302, ['Location' => 'https://news.example.com/3'])
                ->push('', 302, ['Location' => 'https://news.example.com/4'])
                ->push('', 302, ['Location' => 'https://news.example.com/5']),
        ]);

        $this->assertFails('https://news.example.com/1', 'too_many_redirects');
        Http::assertSentCount(4);
    }

    public function test_a_redirect_to_a_private_address_is_refused_before_it_is_requested(): void
    {
        $this->dns['internal.example.com'] = ['10.0.0.7'];
        Http::fake([
            'https://news.example.com/rss' => Http::response('', 302, ['Location' => 'https://internal.example.com/admin']),
        ]);

        $this->assertFails('https://news.example.com/rss', 'blocked_address');
        Http::assertSentCount(1);
    }

    public function test_a_redirect_to_http_or_an_ip_is_refused(): void
    {
        Http::fake([
            'https://news.example.com/a' => Http::response('', 302, ['Location' => 'http://news.example.com/b']),
            'https://news.example.com/c' => Http::response('', 302, ['Location' => 'https://169.254.169.254/latest/meta-data']),
            'https://news.example.com/d' => Http::response('', 302),
        ]);

        $this->assertFails('https://news.example.com/a', 'invalid_url');
        $this->assertFails('https://news.example.com/c', 'invalid_url');
        $this->assertFails('https://news.example.com/d', 'invalid_url');
        Http::assertSentCount(3);
    }

    public function test_bodies_over_one_megabyte_are_refused(): void
    {
        Http::fake([
            'https://news.example.com/big' => Http::response(str_repeat('a', 1_048_577)),
            'https://news.example.com/limit' => Http::response(str_repeat('a', 1_048_576)),
        ]);

        $this->assertFails('https://news.example.com/big', 'too_large');
        $this->assertSame(1_048_576, strlen($this->fetcher()->fetch('https://news.example.com/limit')));
    }

    public function test_error_statuses_and_connection_failures_give_short_codes(): void
    {
        Http::fake([
            'https://news.example.com/missing' => Http::response('<html>Not found, secret body</html>', 404),
            'https://news.example.com/down' => Http::response('', 503),
            'https://news.example.com/timeout' => Http::failedConnection(),
        ]);

        $this->assertFails('https://news.example.com/missing', 'http_404');
        $this->assertFails('https://news.example.com/down', 'http_503');
        $this->assertFails('https://news.example.com/timeout', 'connection_failed');
    }
}
