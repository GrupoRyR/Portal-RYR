<?php

namespace Tests\Unit\Catalog;

use App\Catalog\AppUrlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AppUrlPolicyTest extends TestCase
{
    private const HOSTS = ['segurex.com.co', 'derivadoscol.com.co', 'sgx-*.vercel.app'];

    /**
     * @return array<string, array{string}>
     */
    public static function allowedUrls(): array
    {
        return [
            'exact domain' => ['https://segurex.com.co'],
            'subdomain' => ['https://pedidos.segurex.com.co'],
            'nested subdomain' => ['https://a.b.segurex.com.co/x'],
            'path and query' => ['https://portafolio.derivadoscol.com.co/login.php?x=1'],
            'wildcard label' => ['https://sgx-cuentas-por-pagar.vercel.app/login'],
            'uppercase host and scheme' => ['HTTPS://Pedidos.Segurex.COM.co'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedUrls(): array
    {
        return [
            'lookalike suffix' => ['https://segurex.com.co.evil.io'],
            'lookalike prefix' => ['https://evilsegurex.com.co'],
            'plain http' => ['http://pedidos.segurex.com.co'],
            'javascript scheme' => ['javascript:alert(1)'],
            'data scheme' => ['data:text/html,hola'],
            'unlisted host' => ['https://example.com'],
            'wildcard spans two labels' => ['https://sgx-a.b.vercel.app'],
            'wildcard needs its prefix' => ['https://cartera.vercel.app'],
            'wildcard parent' => ['https://vercel.app'],
            'empty wildcard label' => ['https://sgx-.vercel.app'],
            'user info' => ['https://segurex.com.co@evil.io'],
            'user info on allowed host' => ['https://user:pw@segurex.com.co'],
            'explicit port' => ['https://segurex.com.co:8443'],
            'trailing dot' => ['https://segurex.com.co.'],
            'protocol relative' => ['//segurex.com.co'],
            'no scheme' => ['segurex.com.co'],
            'empty' => [''],
            'whitespace' => [' https://segurex.com.co'],
        ];
    }

    #[DataProvider('allowedUrls')]
    public function test_it_allows_https_urls_on_listed_hosts(string $url): void
    {
        $this->assertTrue((new AppUrlPolicy(self::HOSTS))->allows($url));
    }

    #[DataProvider('rejectedUrls')]
    public function test_it_rejects_anything_else(string $url): void
    {
        $this->assertFalse((new AppUrlPolicy(self::HOSTS))->allows($url));
    }

    public function test_an_empty_host_list_allows_nothing(): void
    {
        $this->assertFalse((new AppUrlPolicy([]))->allows('https://segurex.com.co'));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function patterns(): array
    {
        return [
            'plain domain' => ['segurex.com.co', true],
            'subdomain' => ['pedidos.segurex.com.co', true],
            'wildcard with prefix' => ['sgx-*.vercel.app', true],
            'wildcard with suffix' => ['*-sgx.vercel.app', true],
            'single label' => ['localhost', false],
            'bare wildcard label' => ['*.vercel.app', false],
            'wildcard in the domain' => ['sgx.*.app', false],
            'wildcard in the top level' => ['segurex.*', false],
            'two wildcards' => ['a-*-*.vercel.app', false],
            'scheme' => ['https://segurex.com.co', false],
            'path' => ['segurex.com.co/x', false],
            'port' => ['segurex.com.co:443', false],
            'uppercase' => ['Segurex.com.co', false],
            'empty label' => ['segurex..co', false],
            'leading hyphen' => ['-segurex.com.co', false],
            'spaces' => ['segurex .com.co', false],
            'empty' => ['', false],
            'too long' => [implode('.', array_fill(0, 5, str_repeat('a', 60))).'.com', false],
            'label too long' => [str_repeat('a', 64).'.com', false],
        ];
    }

    #[DataProvider('patterns')]
    public function test_it_validates_host_patterns(string $pattern, bool $valid): void
    {
        $this->assertSame($valid, AppUrlPolicy::isValidPattern($pattern));
    }
}
