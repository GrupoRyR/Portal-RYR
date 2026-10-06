<?php

namespace App\Support;

/**
 * Case-insensitive "contains" patterns for `lower(column) like ? escape '!'`.
 * The user's `%` and `_` are matched literally. `!` is the escape character
 * because a backslash would itself need escaping in MySQL string literals.
 */
final class Like
{
    /** SQL fragment to append after `like ?`. */
    public const ESCAPE = "escape '!'";

    public static function contains(string $term): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';
    }
}
