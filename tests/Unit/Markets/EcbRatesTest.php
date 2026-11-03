<?php

namespace Tests\Unit\Markets;

use App\Markets\EcbRates;
use App\Markets\MarketSourceException;
use PHPUnit\Framework\TestCase;

/**
 * The USD-per-EUR rate of the ECB daily reference XML.
 */
class EcbRatesTest extends TestCase
{
    private function xml(string $cubes = "<Cube currency='USD' rate='1.0800'/><Cube currency='JPY' rate='165.20'/>", string $time = '2026-11-03'): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
    <gesmes:subject>Reference rates</gesmes:subject>
    <gesmes:Sender><gesmes:name>European Central Bank</gesmes:name></gesmes:Sender>
    <Cube><Cube time='{$time}'>{$cubes}</Cube></Cube>
</gesmes:Envelope>
XML;
    }

    private function reasonOf(string $xml): string
    {
        try {
            EcbRates::usdPerEur($xml);
        } catch (MarketSourceException $e) {
            return $e->reason;
        }

        return 'accepted';
    }

    public function test_it_reads_the_date_and_the_dollar_rate(): void
    {
        $this->assertSame(['date' => '2026-11-03', 'rate' => '1.0800'], EcbRates::usdPerEur($this->xml()));
    }

    public function test_it_rejects_a_doctype(): void
    {
        $xml = <<<'XML'
<?xml version="1.0"?>
<!DOCTYPE gesmes:Envelope [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref"><Cube><Cube time='2026-11-03'><Cube currency='USD' rate='1.08'/></Cube></Cube></gesmes:Envelope>
XML;

        $this->assertSame('doctype', $this->reasonOf($xml));
        $this->assertSame('doctype', $this->reasonOf(str_replace('<!DOCTYPE', '<!doctype', $xml)));
    }

    public function test_it_rejects_markup_that_is_not_xml_or_has_nul_bytes(): void
    {
        $this->assertSame('invalid_xml', $this->reasonOf('<html><body>Service unavailable'));
        $this->assertSame('invalid_xml', $this->reasonOf(''));
        $this->assertSame('invalid_xml', $this->reasonOf("\xFF\xFE<\0g\0"));
    }

    public function test_it_rejects_a_missing_dollar_rate_or_date(): void
    {
        $this->assertSame('invalid_data', $this->reasonOf($this->xml("<Cube currency='JPY' rate='165.20'/>")));
        $this->assertSame('invalid_data', $this->reasonOf($this->xml(time: 'yesterday')));
        $this->assertSame('invalid_data', $this->reasonOf($this->xml(time: '2026-02-31')));
    }

    public function test_it_rejects_a_rate_that_is_not_a_positive_number(): void
    {
        foreach (['0', '-1.08', 'abc', '1e3', '', '1,08'] as $rate) {
            $this->assertSame('invalid_data', $this->reasonOf($this->xml("<Cube currency='USD' rate='{$rate}'/>")), $rate);
        }
    }

    public function test_it_ignores_a_usd_cube_in_another_namespace(): void
    {
        $xml = str_replace("<Cube currency='USD' rate='1.0800'/>", "<x:Cube xmlns:x='urn:other' currency='USD' rate='9.99'/>", $this->xml());

        $this->assertSame('invalid_data', $this->reasonOf($xml));
    }
}
