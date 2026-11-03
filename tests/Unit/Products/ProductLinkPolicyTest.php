<?php

namespace Tests\Unit\Products;

use App\Products\ProductLinkPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProductLinkPolicyTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function accepted(): array
    {
        return [
            'plain' => ['https://segurex.com.co/fichas/camara.pdf'],
            'subdomain and query' => ['https://cdn.sdc.example.org/fds/hipoclorito.pdf?v=2#page=3'],
            'uppercase scheme and host' => ['HTTPS://Segurex.com.co/a'],
        ];
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function rejected(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'http' => ['http://segurex.com.co/ficha.pdf'],
            'javascript' => ['javascript:alert(1)'],
            'data' => ['data:text/html,<script>alert(1)</script>'],
            'protocol relative' => ['//segurex.com.co/ficha.pdf'],
            'no scheme' => ['segurex.com.co/ficha.pdf'],
            'user info' => ['https://user:pass@segurex.com.co/'],
            'explicit port' => ['https://segurex.com.co:8443/'],
            'lookalike authority' => ['https://segurex.com.co@evil.io/'],
            'single label host' => ['https://localhost/'],
            'ip address host' => ['https://10.0.0.1/ficha.pdf'],
            'space inside' => ['https://segurex.com.co/a b'],
            'surrounding space' => [' https://segurex.com.co/'],
            'trailing dot host' => ['https://segurex.com.co./'],
            'underscore host' => ['https://seg_urex.com.co/'],
        ];
    }

    #[DataProvider('accepted')]
    public function test_it_accepts_https_links(string $url): void
    {
        $this->assertTrue(ProductLinkPolicy::allows($url));
    }

    #[DataProvider('rejected')]
    public function test_it_rejects_anything_that_is_not_a_plain_https_link(?string $url): void
    {
        $this->assertFalse(ProductLinkPolicy::allows($url));
    }
}
