<?php

namespace App\Support;

/**
 * The address of the ticket platform with where the person comes from
 * (brief §4): `origen=portal`, the company slug and, when known, the app
 * slug. Never personal data: the platform identifies the person with
 * their own Microsoft sign-in.
 *
 * The configured query string and fragment are kept; a parameter of the
 * configured address with one of these names is replaced, not repeated.
 */
final class SupportLink
{
    public const PARAMS = ['origen', 'empresa', 'app'];

    public static function build(string $base, ?string $company, ?string $app): string
    {
        $params = array_filter(
            ['origen' => 'portal', 'empresa' => $company, 'app' => $app],
            fn (?string $value) => $value !== null && $value !== '',
        );

        [$address, $fragment] = array_pad(explode('#', $base, 2), 2, null);
        [$path, $query] = array_pad(explode('?', $address, 2), 2, null);

        $kept = array_filter(
            explode('&', (string) $query),
            fn (string $pair) => $pair !== '' && ! in_array(urldecode(explode('=', $pair, 2)[0]), self::PARAMS, true),
        );

        $query = implode('&', [...$kept, http_build_query($params, '', '&', PHP_QUERY_RFC3986)]);

        return $path.'?'.$query.($fragment === null ? '' : '#'.$fragment);
    }
}
