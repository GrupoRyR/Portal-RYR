<?php

namespace App\View\Home;

/**
 * The href of a link that comes from data (a product, a work item, a
 * headline): only an absolute http(s) URL, so a stored `javascript:` or
 * `data:` value never becomes a link on the home. The writers already
 * check their URLs; this is the guard at render time.
 */
final class WebLink
{
    public static function href(?string $url): ?string
    {
        $url = trim((string) $url);

        return preg_match('~\Ahttps?://[^\s/?#]~i', $url) === 1 ? $url : null;
    }
}
