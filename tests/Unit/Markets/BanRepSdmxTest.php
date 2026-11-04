<?php

namespace Tests\Unit\Markets;

use App\Markets\BanRepSdmx;
use App\Markets\MarketSourceException;
use PHPUnit\Framework\TestCase;

/**
 * The observations of a Banco de la República SDMX-ML 2.1 generic message
 * (tests/Fixtures/markets are hand-made in that shape).
 */
class BanRepSdmxTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/markets/'.$name);
    }

    /**
     * A message with the given series (each an XML fragment of <generic:Obs>).
     */
    private function message(string ...$series): string
    {
        $body = implode('', array_map(fn (string $obs) => "<generic:Series><generic:SeriesKey><generic:Value id=\"FREQ\" value=\"D\"/></generic:SeriesKey>{$obs}</generic:Series>", $series));

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<message:GenericData xmlns:message="http://www.sdmx.org/resources/sdmxml/schemas/v2_1/message" xmlns:generic="http://www.sdmx.org/resources/sdmxml/schemas/v2_1/data/generic">'
            ."<message:Header><message:ID>X</message:ID></message:Header><message:DataSet>{$body}</message:DataSet></message:GenericData>";
    }

    private function obs(string $period, string $value): string
    {
        return "<generic:Obs><generic:ObsDimension value=\"{$period}\"/><generic:ObsValue value=\"{$value}\"/></generic:Obs>";
    }

    public function test_it_reads_the_daily_observations_newest_first(): void
    {
        $this->assertSame([
            ['date' => '2026-11-03', 'value' => '9.0000'],
            ['date' => '2026-11-02', 'value' => '9.2500'],
            ['date' => '2026-11-01', 'value' => '9.2500'],
            ['date' => '2026-10-31', 'value' => '9.2500'],
            ['date' => '2026-10-30', 'value' => '9.2500'],
        ], BanRepSdmx::observations($this->fixture('banrep-tpm-daily.xml')));
    }

    public function test_a_monthly_period_is_the_first_day_of_its_month(): void
    {
        $this->assertSame([
            ['date' => '2026-10-01', 'value' => '1512.3000'],
            ['date' => '2026-09-01', 'value' => '1499.9000'],
            ['date' => '2026-08-01', 'value' => '1478.2100'],
        ], BanRepSdmx::observations($this->fixture('banrep-colcap-monthly.xml')));

        // The SDMX «2026-M09» spelling too.
        $this->assertSame([['date' => '2026-09-01', 'value' => '1499.9000']], BanRepSdmx::observations($this->message($this->obs('2026-M09', '1499.9'))));
    }

    public function test_observations_that_are_not_a_number_on_a_real_date_are_skipped(): void
    {
        $xml = $this->message(
            $this->obs('2026-11-03', 'NaN')
            .$this->obs('2026-02-30', '9.25')
            .$this->obs('2026-11-02', '-1')
            .$this->obs('2026-11-01', '9,25')
            .$this->obs('2026-10-31', '1e3')
            .$this->obs('2026-10-30', '9.5'),
        );

        $this->assertSame([['date' => '2026-10-30', 'value' => '9.5000']], BanRepSdmx::observations($xml));
    }

    public function test_a_message_without_usable_observations_is_invalid(): void
    {
        $this->expectExceptionObject(new MarketSourceException('invalid_data'));

        BanRepSdmx::observations($this->message($this->obs('2026-11-03', 'NaN')));
    }

    public function test_an_error_message_without_series_is_invalid(): void
    {
        $this->expectExceptionObject(new MarketSourceException('invalid_data'));

        BanRepSdmx::observations('<?xml version="1.0"?><message:Error xmlns:message="http://www.sdmx.org/resources/sdmxml/schemas/v2_1/message"><message:ErrorMessage code="500"/></message:Error>');
    }

    public function test_more_than_one_series_is_refused_rather_than_guessed(): void
    {
        $this->expectExceptionObject(new MarketSourceException('many_series'));

        BanRepSdmx::observations($this->message($this->obs('2026-11-03', '9.0'), $this->obs('2026-11-03', '4.1')));
    }

    public function test_a_doctype_is_refused(): void
    {
        $xml = str_replace('<?xml version="1.0" encoding="UTF-8"?>', '<?xml version="1.0"?><!DOCTYPE m [<!ENTITY x SYSTEM "file:///etc/passwd">]>', $this->fixture('banrep-tpm-daily.xml'));

        $this->expectExceptionObject(new MarketSourceException('doctype'));

        BanRepSdmx::observations($xml);
    }

    public function test_a_body_that_is_not_xml_is_invalid(): void
    {
        foreach (['', '<html>maintenance', "\0<x/>", '{"a":1}'] as $body) {
            try {
                BanRepSdmx::observations($body);
                $this->fail('Accepted: '.$body);
            } catch (MarketSourceException $e) {
                $this->assertSame('invalid_xml', $e->reason);
            }
        }
    }
}
