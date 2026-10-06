<?php

namespace App\Branding;

use enshrined\svgSanitize\Sanitizer;
use Throwable;

/**
 * Cleans uploaded SVG logos before they are stored.
 *
 * An SVG is a document, not a picture: opened directly from /storage it
 * could run scripts on the portal's origin. The allow-list sanitizer drops
 * scripts, event handlers, javascript: links and remote references.
 */
final class SvgLogo
{
    /**
     * The sanitized markup, or null when the content is not a usable SVG.
     */
    public static function sanitize(string $content): ?string
    {
        $sanitizer = new Sanitizer;
        $sanitizer->removeRemoteReferences(true);

        try {
            $clean = $sanitizer->sanitize($content);
        } catch (Throwable) {
            // The library throws when the document has no single <svg> root.
            return null;
        }

        if (! is_string($clean) || ! preg_match('/<svg[\s>]/i', $clean)) {
            return null;
        }

        return $clean;
    }
}
