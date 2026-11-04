<?php

namespace Tests\Feature\Home;

use App\View\Home\MarketFigure;
use App\View\Home\MarketTicker;
use PHPUnit\Framework\TestCase;

/**
 * How the «Mercados» band moves (App\View\Home\MarketTicker): still under
 * two figures, otherwise enough copies to cover the widest band and a lap
 * that grows with the list.
 */
class MarketTickerTest extends TestCase
{
    public function test_no_figures_do_not_move(): void
    {
        $ticker = MarketTicker::for([]);

        $this->assertFalse($ticker->moves);
        $this->assertSame(1, $ticker->groups);
        $this->assertSame(0, $ticker->seconds);
    }

    public function test_one_figure_does_not_move(): void
    {
        $ticker = MarketTicker::for($this->figures(1));

        $this->assertFalse($ticker->moves);
        $this->assertSame(1, $ticker->groups);
        $this->assertSame(0, $ticker->seconds);
    }

    public function test_a_moving_band_always_has_a_copy_to_follow_the_list(): void
    {
        foreach ([2, 3, 9, 40] as $count) {
            $ticker = MarketTicker::for($this->figures($count));

            $this->assertTrue($ticker->moves, "{$count} figures");
            $this->assertGreaterThanOrEqual(2, $ticker->groups, "{$count} figures");
        }
    }

    public function test_two_figures_need_four_copies_to_cover_the_widest_band(): void
    {
        // 1440 px ÷ (2 × 200 px) = 3.6 → 4 copies after the list.
        $ticker = MarketTicker::for($this->figures(2));

        $this->assertSame(5, $ticker->groups);
        $this->assertSame(2 * MarketTicker::SECONDS_PER_FIGURE, $ticker->seconds);
    }

    public function test_nine_figures_need_one_copy(): void
    {
        // 1440 px ÷ (9 × 200 px) = 0.8 → 1 copy after the list.
        $ticker = MarketTicker::for($this->figures(9));

        $this->assertSame(2, $ticker->groups);
        $this->assertSame(9 * MarketTicker::SECONDS_PER_FIGURE, $ticker->seconds);
    }

    /**
     * @return list<MarketFigure>
     */
    private function figures(int $count): array
    {
        return array_map(
            fn (int $i) => new MarketFigure("m{$i}", "Mercado {$i}", '$1,00', 'up', '1,00 %', '0,01', '2026-11-03', '3 nov'),
            range(1, $count),
        );
    }
}
