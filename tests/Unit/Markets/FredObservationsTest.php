<?php

namespace Tests\Unit\Markets;

use App\Markets\FredObservations;
use App\Markets\MarketSourceException;
use PHPUnit\Framework\TestCase;

/**
 * The observations of a FRED `series/observations` JSON answer.
 */
class FredObservationsTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/markets/'.$name);
    }

    public function test_it_reads_the_observations_and_skips_the_missing_ones(): void
    {
        // FRED marks a day without a value (a holiday) with «.».
        $this->assertSame([
            ['date' => '2026-10-27', 'value' => '64.5300'],
            ['date' => '2026-10-26', 'value' => '65.7300'],
            ['date' => '2026-10-22', 'value' => '66.1000'],
        ], FredObservations::parse($this->fixture('fred-brent-daily.json')));
    }

    public function test_monthly_observations_keep_their_first_of_month_date(): void
    {
        $this->assertSame([
            ['date' => '2026-09-01', 'value' => '2845.5000'],
            ['date' => '2026-08-01', 'value' => '2800.4523'],
            ['date' => '2026-07-01', 'value' => '2761.0800'],
        ], FredObservations::parse($this->fixture('fred-zinc-monthly.json')));
    }

    public function test_rows_that_are_not_a_number_on_a_real_date_are_skipped(): void
    {
        $json = json_encode(['observations' => [
            ['date' => '2026-10-27', 'value' => '-3'],
            ['date' => '2026-02-30', 'value' => '1'],
            ['date' => '2026-10-26', 'value' => 65.7],
            ['date' => '2026-10-25T00:00', 'value' => '1'],
            'nope',
            ['date' => '2026-10-24', 'value' => '1e5'],
            ['date' => '2026-10-23', 'value' => '61.2'],
            ['date' => '2026-10-22', 'value' => '60.100000000000000001'],
        ]]);

        $this->assertSame([['date' => '2026-10-23', 'value' => '61.2000']], FredObservations::parse($json));
    }

    public function test_a_repeated_date_keeps_the_last_published_like_banrep(): void
    {
        $json = json_encode(['observations' => [
            ['date' => '2026-10-23', 'value' => '61.2'],
            ['date' => '2026-10-23', 'value' => '61.25000000000001'],
        ]]);

        $this->assertSame([['date' => '2026-10-23', 'value' => '61.2500']], FredObservations::parse($json));
    }

    public function test_an_answer_without_usable_observations_is_invalid(): void
    {
        foreach ([
            '{"observations":[{"date":"2026-10-27","value":"."}]}',
            '{"observations":[]}',
            '{"observations":{"a":1}}',
            '{"error_code":400,"error_message":"Bad Request."}',
            '[]',
        ] as $json) {
            try {
                FredObservations::parse($json);
                $this->fail('Accepted: '.$json);
            } catch (MarketSourceException $e) {
                $this->assertSame('invalid_data', $e->reason, $json);
            }
        }
    }

    public function test_a_body_that_is_not_json_is_invalid(): void
    {
        $this->expectExceptionObject(new MarketSourceException('invalid_json'));

        FredObservations::parse('<html>Service unavailable</html>');
    }
}
