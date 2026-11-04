<?php

namespace App\Markets;

use DOMDocument;

/**
 * Loads an XML body that came from the network (the ECB, the Banco de la
 * República) defensively: a DOCTYPE is refused (no DTD, no entities),
 * entities are never substituted and the parser may not touch the network.
 */
final class SafeXml
{
    /**
     * @throws MarketSourceException `invalid_xml` or `doctype`
     */
    public static function load(string $xml): DOMDocument
    {
        // UTF-16 and the like would hide a DOCTYPE from the check below.
        if ($xml === '' || str_contains($xml, "\0")) {
            throw new MarketSourceException('invalid_xml');
        }

        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw new MarketSourceException('doctype');
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument;

            // No LIBXML_NOENT (entities stay unsubstituted), no DTDLOAD, no network.
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            throw new MarketSourceException('invalid_xml');
        }

        if ($document->doctype !== null) {
            throw new MarketSourceException('doctype');
        }

        return $document;
    }
}
