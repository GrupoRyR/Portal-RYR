<?php

namespace App\Markets;

use DOMElement;
use DOMXPath;

/**
 * Reads the observations of a Banco de la República SDMX-ML 2.1 generic
 * message (the policy rate, COLCAP):
 *
 *     <message:GenericData>
 *       <message:DataSet>
 *         <generic:Series>
 *           <generic:Obs>
 *             <generic:ObsDimension value="2026-11-03"/>   (or «2026-10» for a month)
 *             <generic:ObsValue value="9.25"/>
 *
 * The XML is parsed with SafeXml (no DOCTYPE, no entities, no network).
 * Each dataflow carries one series; a message with more is refused
 * (`many_series`) instead of guessing which one is meant. An observation
 * that is not a plain number on a real date is skipped.
 *
 * @see https://totoro.banrep.gov.co/nsi-jax-ws/rest/data/ESTAT,DF_CBR_DAILY_LATEST,1.0/all/ALL/
 */
final class BanRepSdmx
{
    private const GENERIC = 'http://www.sdmx.org/resources/sdmxml/schemas/v2_1/data/generic';

    /**
     * @return non-empty-list<array{date: string, value: string}> Newest first, one per date.
     *
     * @throws MarketSourceException `invalid_xml`, `doctype`, `many_series` or `invalid_data`
     */
    public static function observations(string $xml): array
    {
        $xpath = new DOMXPath(SafeXml::load($xml));
        $xpath->registerNamespace('g', self::GENERIC);

        $series = $xpath->query('//g:Series');

        if ($series === false || $series->length === 0) {
            throw new MarketSourceException('invalid_data');
        }

        if ($series->length > 1) {
            throw new MarketSourceException('many_series');
        }

        $rows = [];

        foreach ($xpath->query('g:Obs', $series->item(0)) ?: [] as $obs) {
            $date = Observation::date(self::attribute($xpath, 'g:ObsDimension', $obs), monthsToo: true);
            $value = Observation::amount(self::attribute($xpath, 'g:ObsValue', $obs));

            if ($date !== null && $value !== null) {
                $rows[$date] = ['date' => $date, 'value' => $value];
            }
        }

        if ($rows === []) {
            throw new MarketSourceException('invalid_data');
        }

        krsort($rows);

        return array_values($rows);
    }

    /**
     * The `value` attribute of the only child `$path` of an observation.
     */
    private static function attribute(DOMXPath $xpath, string $path, mixed $obs): ?string
    {
        $nodes = $obs instanceof DOMElement ? $xpath->query($path, $obs) : false;

        if ($nodes === false || $nodes->length !== 1 || ! $nodes->item(0) instanceof DOMElement) {
            return null;
        }

        return $nodes->item(0)->getAttribute('value');
    }
}
