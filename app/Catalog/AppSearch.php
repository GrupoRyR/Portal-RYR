<?php

namespace App\Catalog;

use Illuminate\Support\Str;

/**
 * Search over app names and descriptions, ignoring accents, case and extra
 * spaces: «camaras» finds «Cámaras». Every word of the query must appear.
 *
 * resources/js/home.js filters the rendered cards the same way, reading the
 * text from normalize() in each card's data-search attribute.
 */
final readonly class AppSearch
{
    public const MAX_LENGTH = 100;

    private string $query;

    /** @var list<string> */
    private array $terms;

    public function __construct(?string $query)
    {
        $this->query = mb_substr(trim((string) $query), 0, self::MAX_LENGTH);

        $normalized = self::normalize($this->query);
        $this->terms = $normalized === '' ? [] : explode(' ', $normalized);
    }

    /**
     * Lowercase ASCII with single spaces («Cámaras  DE» → «camaras de»).
     */
    public static function normalize(?string $text): string
    {
        $ascii = Str::ascii(mb_strtolower((string) $text));

        return trim((string) preg_replace('/\s+/', ' ', $ascii));
    }

    public function query(): string
    {
        return $this->query;
    }

    public function isActive(): bool
    {
        return $this->terms !== [];
    }

    public function matches(string ...$fields): bool
    {
        $haystack = self::normalize(implode(' ', $fields));

        foreach ($this->terms as $term) {
            if (! str_contains($haystack, $term)) {
                return false;
            }
        }

        return true;
    }
}
