<?php

namespace Tests\Unit\Catalog;

use App\Catalog\AppSearch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AppSearchTest extends TestCase
{
    public function test_normalizing_drops_accents_case_and_extra_spaces(): void
    {
        $this->assertSame('camaras de seguridad', AppSearch::normalize("  Cámaras   DE\tSeguridad "));
        $this->assertSame('tesoreria y pequena caja', AppSearch::normalize('Tesorería y PEQUEÑA caja'));
        $this->assertSame('', AppSearch::normalize(null));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function queries(): array
    {
        return [
            'without accents' => ['camaras', true],
            'with accents' => ['CÁMARAS', true],
            'partial word' => ['cám', true],
            'description' => ['seguridad', true],
            'every word must match' => ['camaras monitoreo', true],
            'one word missing' => ['camaras ventas', false],
            'unrelated' => ['cartera', false],
            'empty query matches all' => ['   ', true],
        ];
    }

    #[DataProvider('queries')]
    public function test_matching_is_accent_and_case_insensitive(string $query, bool $expected): void
    {
        $search = new AppSearch($query);

        $this->assertSame($expected, $search->matches('Cámaras', 'Monitoreo de cámaras de seguridad'));
    }

    public function test_a_blank_query_is_not_active(): void
    {
        $this->assertFalse((new AppSearch('  '))->isActive());
        $this->assertTrue((new AppSearch('ca'))->isActive());
        $this->assertSame('ca', (new AppSearch('  ca '))->query());
    }

    public function test_overlong_queries_are_cut(): void
    {
        $this->assertSame(100, mb_strlen((new AppSearch(str_repeat('a', 500)))->query()));
    }
}
