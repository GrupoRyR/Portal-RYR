<?php

namespace Tests\Unit\View;

use App\View\Home\Greeting;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GreetingTest extends TestCase
{
    /**
     * Times in UTC; Bogotá is UTC−5 all year (no daylight saving).
     *
     * @return array<string, array{string, string}>
     */
    public static function times(): array
    {
        return [
            'early morning in Bogotá' => ['2026-10-05 10:00:00', 'Buenos días'],
            'last minute of the morning' => ['2026-10-05 16:59:00', 'Buenos días'],
            'noon' => ['2026-10-05 17:00:00', 'Buenas tardes'],
            'late afternoon' => ['2026-10-05 23:59:00', 'Buenas tardes'],
            'seven in the evening' => ['2026-10-06 00:00:00', 'Buenas noches'],
            'past midnight' => ['2026-10-06 07:30:00', 'Buenas noches'],
            'five in the morning' => ['2026-10-06 10:00:00', 'Buenos días'],
        ];
    }

    #[DataProvider('times')]
    public function test_the_salutation_follows_bogota_time(string $utc, string $expected): void
    {
        $this->assertSame($expected, Greeting::at(CarbonImmutable::parse($utc, 'UTC'))->salutation);
    }

    public function test_the_date_line_is_spanish_and_in_bogota(): void
    {
        // 03:00 UTC on Tuesday is still Monday evening in Bogotá.
        $greeting = Greeting::at(CarbonImmutable::parse('2026-10-06 03:00:00', 'UTC'));

        $this->assertSame('Lunes, 5 de octubre', $greeting->dateLine);
    }

    public function test_the_first_name_is_the_first_word(): void
    {
        $this->assertSame('Ana', Greeting::firstName('  Ana María Pérez '));
        $this->assertSame('', Greeting::firstName(null));
    }
}
