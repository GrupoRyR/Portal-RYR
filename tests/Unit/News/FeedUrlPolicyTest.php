<?php

namespace Tests\Unit\News;

use App\News\FeedUrlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FeedUrlPolicyTest extends TestCase
{
    /**
     * @return array<string, array{?string}>
     */
    public static function refusedUrls(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'http' => ['http://news.example.com/rss'],
            'ftp' => ['ftp://news.example.com/rss'],
            'file' => ['file:///etc/passwd'],
            'gopher' => ['gopher://news.example.com/'],
            'no scheme' => ['news.example.com/rss'],
            'protocol relative' => ['//news.example.com/rss'],
            'explicit 443' => ['https://news.example.com:443/rss'],
            'other port' => ['https://news.example.com:8443/rss'],
            'userinfo' => ['https://user@news.example.com/rss'],
            'user and password' => ['https://user:pass@news.example.com/rss'],
            'backslash trick' => ['https://news.example.com\\@evil.com/rss'],
            'ipv4 literal' => ['https://127.0.0.1/rss'],
            'public ipv4 literal' => ['https://8.8.8.8/rss'],
            'decimal ip' => ['https://2130706433/rss'],
            'hex ip' => ['https://0x7f.0.0.1/rss'],
            'ipv6 literal' => ['https://[::1]/rss'],
            'single label' => ['https://localhost/rss'],
            'localhost subdomain' => ['https://feeds.localhost/rss'],
            'trailing dot' => ['https://news.example.com./rss'],
            'spaces' => [' https://news.example.com/rss'],
            'control char' => ["https://news.example.com/r\nss"],
            'underscore host' => ['https://news_x.example.com/rss'],
            'too long' => ['https://news.example.com/'.str_repeat('a', 2048)],
        ];
    }

    #[DataProvider('refusedUrls')]
    public function test_unsafe_or_malformed_urls_are_refused(?string $url): void
    {
        $this->assertNull(FeedUrlPolicy::hostOf($url));
    }

    public function test_a_plain_https_url_gives_its_lowercase_host(): void
    {
        $this->assertSame('news.example.com', FeedUrlPolicy::hostOf('https://News.Example.com/rss?x=1#top'));
        $this->assertSame('www.eltiempo.com', FeedUrlPolicy::hostOf('https://www.eltiempo.com'));
    }

    public function test_item_links_must_be_https_and_short(): void
    {
        $this->assertTrue(FeedUrlPolicy::isSafeLink('https://www.example.com/a?b=c#d'));
        $this->assertTrue(FeedUrlPolicy::isSafeLink('https://www.example.com:8443/a'));
        $this->assertFalse(FeedUrlPolicy::isSafeLink('http://www.example.com/a'));
        $this->assertFalse(FeedUrlPolicy::isSafeLink('javascript:alert(1)'));
        $this->assertFalse(FeedUrlPolicy::isSafeLink('data:text/html,<script>alert(1)</script>'));
        $this->assertFalse(FeedUrlPolicy::isSafeLink('https://user:pw@www.example.com/a'));
        $this->assertFalse(FeedUrlPolicy::isSafeLink('https://www.example.com/a b'));
        $this->assertFalse(FeedUrlPolicy::isSafeLink('/relative/path'));
        $this->assertFalse(FeedUrlPolicy::isSafeLink('https://www.example.com/'.str_repeat('a', 2048)));
    }
}
