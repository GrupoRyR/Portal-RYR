<?php

namespace App\Markets;

use DOMElement;
use DOMXPath;

/**
 * Reads the dollars per euro from the ECB daily reference rates XML
 * (`<Cube time="2026-11-03"><Cube currency="USD" rate="1.0800"/>…`).
 *
 * The XML comes from the network, so it is parsed defensively (SafeXml:
 * no DOCTYPE, no entities, no network), and the date and the rate are
 * checked before they are believed.
 *
 * @see https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml
 */
final class EcbRates
{
    private const NAMESPACE = 'http://www.ecb.int/vocabulary/2002-08-01/eurofxref';

    private const RATE = '/\A\d{1,3}(\.\d{1,8})?\z/';

    private const DATE = '/\A(\d{4})-(\d{2})-(\d{2})\z/';

    /**
     * @return array{date: string, rate: string} The reference date and the USD per EUR.
     *
     * @throws MarketSourceException `invalid_xml`, `doctype` or `invalid_data`
     */
    public static function usdPerEur(string $xml): array
    {
        $document = SafeXml::load($xml);

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('e', self::NAMESPACE);
        $nodes = $xpath->query("/*/e:Cube/e:Cube[@time]/e:Cube[@currency='USD']");

        if ($nodes === false || $nodes->length !== 1 || ! $nodes->item(0) instanceof DOMElement) {
            throw new MarketSourceException('invalid_data');
        }

        $cube = $nodes->item(0);
        $date = $cube->parentNode instanceof DOMElement ? $cube->parentNode->getAttribute('time') : '';
        $rate = $cube->getAttribute('rate');

        if (preg_match(self::DATE, $date, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            || preg_match(self::RATE, $rate) !== 1 || (float) $rate <= 0) {
            throw new MarketSourceException('invalid_data');
        }

        return ['date' => $date, 'rate' => $rate];
    }
}
