<?php

namespace Tests\Unit\Support;

use App\Support\SupportLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SupportLinkTest extends TestCase
{
    /**
     * @return array<string, array{string, ?string, ?string, string}>
     */
    public static function links(): array
    {
        return [
            'plain address' => ['https://soporte.segurex.com.co', 'segurex', 'cartera', 'https://soporte.segurex.com.co?origen=portal&empresa=segurex&app=cartera'],
            'without an app' => ['https://soporte.segurex.com.co/nuevo', 'segurex', null, 'https://soporte.segurex.com.co/nuevo?origen=portal&empresa=segurex'],
            'without a company' => ['https://soporte.segurex.com.co/nuevo', null, null, 'https://soporte.segurex.com.co/nuevo?origen=portal'],
            'keeps the query string' => ['https://soporte.segurex.com.co/?form=ti&x=1', 'derivados', 'pedidos', 'https://soporte.segurex.com.co/?form=ti&x=1&origen=portal&empresa=derivados&app=pedidos'],
            'replaces its own parameters' => ['https://soporte.segurex.com.co/?origen=web&form=ti&app=otra', 'segurex', 'cartera', 'https://soporte.segurex.com.co/?form=ti&origen=portal&empresa=segurex&app=cartera'],
            'keeps the fragment last' => ['https://soporte.segurex.com.co/#/nuevo', 'segurex', null, 'https://soporte.segurex.com.co/?origen=portal&empresa=segurex#/nuevo'],
            'empty query' => ['https://soporte.segurex.com.co/?', 'segurex', null, 'https://soporte.segurex.com.co/?origen=portal&empresa=segurex'],
            'encodes the values' => ['https://soporte.segurex.com.co', 'a b', 'c&d', 'https://soporte.segurex.com.co?origen=portal&empresa=a%20b&app=c%26d'],
        ];
    }

    #[DataProvider('links')]
    public function test_it_adds_where_the_person_comes_from(string $base, ?string $company, ?string $app, string $expected): void
    {
        $this->assertSame($expected, SupportLink::build($base, $company, $app));
    }
}
