<?php

namespace App\View\Home;

/**
 * How the «Mercados» band moves (components/home/markets): a CSS marquee
 * that shifts the list left by one copy per lap, so the list is printed
 * `$groups` times (the copies hidden from screen readers). With fewer than
 * two figures it stays still.
 *
 * The copies must cover the band while the first one slides away, and
 * without JavaScript the band's width is unknown: the count assumes the
 * widest band and a narrow figure, so a short list gets more copies.
 */
final readonly class MarketTicker
{
    /** Seconds each figure takes to cross: the lap grows with the list. */
    public const SECONDS_PER_FIGURE = 4;

    /** Widest band, in pixels (the home content is at most 1440 px). */
    private const WIDEST_BAND = 1440;

    /** Narrowest figure with its gap, in pixels («Euro $4.478,12 ▼ 0,49 % 3 nov»). */
    private const NARROWEST_FIGURE = 200;

    public function __construct(
        public bool $moves,
        public int $groups,
        public int $seconds,
    ) {}

    /**
     * @param  list<MarketFigure>  $figures
     */
    public static function for(array $figures): self
    {
        $count = count($figures);

        if ($count < 2) {
            return new self(moves: false, groups: 1, seconds: 0);
        }

        return new self(
            moves: true,
            groups: 1 + (int) ceil(self::WIDEST_BAND / ($count * self::NARROWEST_FIGURE)),
            seconds: $count * self::SECONDS_PER_FIGURE,
        );
    }
}
