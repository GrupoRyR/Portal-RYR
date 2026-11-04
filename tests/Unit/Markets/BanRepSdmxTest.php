<?php

namespace Tests\Unit\Markets;

use App\Markets\BanRepSdmx;
use App\Markets\MarketSourceException;
use PHPUnit\Framework\TestCase;

/**
 * The observations of a Banco de la República SDMX-ML 2.1 generic message.
 * `banrep-*-live.xml` are copies of the live answers of 2026-11-04: the
 * `_LATEST` dataflows carry one series with ONE observation, a daily date
 * spelled `20261104` and attributes inside the series and the observation.
 */
class BanRepSdmxTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/markets/'.$name);
    }

    /**
     * A one-series message with the given <generic:Obs> fragments.
     */
    private function message(string $observations): string
    {
        return "<?xml version='1.0' encoding='UTF-8'?>"
            .'<message:GenericData xmlns:message="http://www.sdmx.org/resources/sdmxml/schemas/v2_1/message" xmlns:generic="http://www.sdmx.org/resources/sdmxml/schemas/v2_1/data/generic">'
            ."<message:Header><message:ID>X</message:ID></message:Header><message:DataSet><generic:Series>{$observations}</generic:Series></message:DataSet></message:GenericData>";
    }

    private function obs(string $period, string $value): string
    {
        return "<generic:Obs><generic:ObsDimension value=\"{$period}\"/><generic:ObsValue value=\"{$value}\"/>"
            .'<generic:Attributes><generic:Value id="OBS_STATUS" value="A"/></generic:Attributes></generic:Obs>';
    }

    public function test_it_reads_the_live_policy_rate_with_its_compact_daily_date(): void
    {
        $this->assertSame(
            [['date' => '2026-11-04', 'value' => '12.2500']],
            BanRepSdmx::observations($this->fixture('banrep-cbr-live.xml')),
        );
    }

    public function test_it_reads_the_live_monthly_colcap_on_the_first_day_of_its_month(): void
    {
        $this->assertSame(
            [['date' => '2026-10-01', 'value' => '2536.5100']],
            BanRepSdmx::observations($this->fixture('banrep-colcap-live.xml')),
        );
    }

    public function test_the_other_date_spellings_are_read_too(): void
    {
        $xml = $this->message($this->obs('2026-11-03', '12.5').$this->obs('2026-M09', '2500').$this->obs('20261102', '12.5'));

        $this->assertSame([
            ['date' => '2026-11-03', 'value' => '12.5000'],
            ['date' => '2026-11-02', 'value' => '12.5000'],
            ['date' => '2026-09-01', 'value' => '2500.0000'],
        ], BanRepSdmx::observations($xml));
    }

    public function test_observations_that_are_not_a_number_on_a_real_date_are_skipped(): void
    {
        $xml = $this->message(
            $this->obs('20261103', 'NaN')
            .$this->obs('20260230', '9.25')
            .$this->obs('2026113', '9.25')
            .$this->obs('202611030', '9.25')
            .$this->obs('2026-13', '9.25')
            .$this->obs('2026-1104', '9.25')
            .$this->obs('20261102', '-1')
            .$this->obs('20261101', '9,25')
            .$this->obs('20261031', '1e3')
            .$this->obs('20261030', '9.5'),
        );

        $this->assertSame([['date' => '2026-10-30', 'value' => '9.5000']], BanRepSdmx::observations($xml));
    }

    public function test_a_message_without_usable_observations_is_invalid(): void
    {
        $this->expectExceptionObject(new MarketSourceException('invalid_data'));

        BanRepSdmx::observations($this->message($this->obs('20261103', 'NaN')));
    }

    public function test_an_error_message_without_series_is_invalid(): void
    {
        $this->expectExceptionObject(new MarketSourceException('invalid_data'));

        BanRepSdmx::observations('<?xml version="1.0"?><message:Error xmlns:message="http://www.sdmx.org/resources/sdmxml/schemas/v2_1/message"><message:ErrorMessage code="500"/></message:Error>');
    }

    public function test_more_than_one_series_is_refused_rather_than_guessed(): void
    {
        $this->expectExceptionObject(new MarketSourceException('many_series'));

        BanRepSdmx::observations($this->fixture('banrep-two-series.xml'));
    }

    public function test_a_doctype_is_refused(): void
    {
        $this->expectExceptionObject(new MarketSourceException('doctype'));

        BanRepSdmx::observations($this->fixture('banrep-doctype.xml'));
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
