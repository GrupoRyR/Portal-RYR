<?php

namespace Tests\Unit\Markets;

use App\Markets\MarketSourceException;
use App\Markets\TrmDataset;
use PHPUnit\Framework\TestCase;

/**
 * The TRM rows of the official dataset (datos.gov.co, 32sa-8pi3).
 */
class TrmDatasetTest extends TestCase
{
    public function test_it_reads_the_verified_sample_row(): void
    {
        $rows = TrmDataset::parse('[{"valor":"3182.99","unidad":"COP","vigenciadesde":"2026-10-31T00:00:00.000","vigenciahasta":"2026-11-03T00:00:00.000"}]');

        $this->assertSame([['value' => '3182.9900', 'valid_from' => '2026-10-31', 'valid_until' => '2026-11-03']], $rows);
    }

    public function test_it_skips_rows_that_are_not_pesos_or_not_a_positive_number(): void
    {
        $rows = TrmDataset::parse(json_encode([
            ['valor' => '3182.99', 'unidad' => 'COP', 'vigenciadesde' => '2026-10-31T00:00:00.000', 'vigenciahasta' => '2026-11-03T00:00:00.000'],
            ['valor' => '3100.00', 'unidad' => 'USD', 'vigenciadesde' => '2026-10-30T00:00:00.000', 'vigenciahasta' => '2026-10-30T00:00:00.000'],
            ['valor' => '-5', 'unidad' => 'COP', 'vigenciadesde' => '2026-10-29T00:00:00.000', 'vigenciahasta' => '2026-10-29T00:00:00.000'],
            ['valor' => '0', 'unidad' => 'COP', 'vigenciadesde' => '2026-10-28T00:00:00.000', 'vigenciahasta' => '2026-10-28T00:00:00.000'],
            ['valor' => '3e3', 'unidad' => 'COP', 'vigenciadesde' => '2026-10-27T00:00:00.000', 'vigenciahasta' => '2026-10-27T00:00:00.000'],
            ['valor' => '3,100.5', 'unidad' => 'COP', 'vigenciadesde' => '2026-10-26T00:00:00.000', 'vigenciahasta' => '2026-10-26T00:00:00.000'],
            ['valor' => '3100.5', 'unidad' => 'COP', 'vigenciadesde' => 'not a date', 'vigenciahasta' => null],
            ['valor' => '3100.5', 'unidad' => 'COP', 'vigenciadesde' => '2026-02-31T00:00:00.000', 'vigenciahasta' => null],
            'not a row',
        ]));

        $this->assertCount(1, $rows);
        $this->assertSame('2026-10-31', $rows[0]['valid_from']);
    }

    public function test_a_missing_or_invalid_end_date_is_null(): void
    {
        $rows = TrmDataset::parse('[{"valor":"3182.99","unidad":"COP","vigenciadesde":"2026-10-31T00:00:00.000"},{"valor":"3100","unidad":"COP","vigenciadesde":"2026-10-30T00:00:00.000","vigenciahasta":"soon"}]');

        $this->assertNull($rows[0]['valid_until']);
        $this->assertNull($rows[1]['valid_until']);
    }

    public function test_it_keeps_the_first_row_when_a_start_date_repeats(): void
    {
        $rows = TrmDataset::parse('[{"valor":"3200","unidad":"COP","vigenciadesde":"2026-10-31T00:00:00.000"},{"valor":"3100","unidad":"COP","vigenciadesde":"2026-10-31T00:00:00.000"}]');

        $this->assertCount(1, $rows);
        $this->assertSame('3200.0000', $rows[0]['value']);
    }

    public function test_it_rejects_a_body_that_is_not_a_list_of_rows(): void
    {
        foreach (['', 'not json', '{"error":true}', '"text"', '[]'] as $body) {
            try {
                TrmDataset::parse($body);
                $this->fail("Accepted [{$body}].");
            } catch (MarketSourceException $e) {
                $this->assertContains($e->reason, ['invalid_json', 'invalid_data'], $body);
            }
        }
    }

    public function test_it_rejects_a_list_without_any_usable_row(): void
    {
        try {
            TrmDataset::parse('[{"valor":"x","unidad":"COP","vigenciadesde":"2026-10-31T00:00:00.000"}]');
            $this->fail('Accepted a list with no usable row.');
        } catch (MarketSourceException $e) {
            $this->assertSame('invalid_data', $e->reason);
        }
    }
}
