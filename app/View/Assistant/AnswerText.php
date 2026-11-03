<?php

namespace App\View\Assistant;

use Illuminate\Support\HtmlString;

/**
 * An answer of the assistant as HTML: escaped plain text with its line
 * breaks, and only links to the portal itself (config `app.url`) made
 * clickable. Anything else the model writes, a link elsewhere included,
 * stays text, so an answer can never carry markup or send people off the
 * portal.
 */
final class AnswerText
{
    public static function html(string $text): HtmlString
    {
        $escaped = e($text);
        $base = rtrim((string) config('app.url'), '/');

        if ($base !== '') {
            $escaped = (string) preg_replace_callback(
                // Not a longer host («portal.example.com.evil.net»), not a port or user.
                '~'.preg_quote(e($base), '~').'(?![A-Za-z0-9\-:@]|\.[A-Za-z0-9])(?:/[^\s<>"\'«»]*)?~u',
                function (array $match): string {
                    // A sentence's final punctuation is not part of the link.
                    $url = rtrim($match[0], '.,;:!?)');
                    $rest = substr($match[0], strlen($url));

                    return '<a href="'.$url.'" class="font-semibold break-all text-brand-ink underline underline-offset-4">'.$url.'</a>'.$rest;
                },
                $escaped,
            );
        }

        return new HtmlString(nl2br($escaped, false));
    }
}
