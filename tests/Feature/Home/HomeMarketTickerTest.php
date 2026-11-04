<?php

namespace Tests\Feature\Home;

use App\View\Home\MarketFigure;
use Tests\TestCase;

/**
 * The «Mercados» band as a moving ticker (odd/tasks/liquid-glass.md G5):
 * a CSS marquee with the list repeated (the copies hidden from screen
 * readers), green for a rise, red for a fall, still under reduced motion
 * and with a single figure. Rendered straight from the component so any
 * number of codes can be tried.
 */
class HomeMarketTickerTest extends TestCase
{
    public function test_a_rise_is_green_with_an_up_arrow(): void
    {
        $change = $this->change($this->band([$this->figure('trm', 'up')]), 'trm');

        $this->assertStringContainsString('▲', $change->textContent);
        $this->assertContains('good', $this->classes($change));
        $this->assertNotContains('crit', $this->classes($change));
    }

    public function test_a_fall_is_red_with_a_down_arrow(): void
    {
        $change = $this->change($this->band([$this->figure('eur', 'down')]), 'eur');

        $this->assertStringContainsString('▼', $change->textContent);
        $this->assertContains('crit', $this->classes($change));
        $this->assertNotContains('good', $this->classes($change));
    }

    public function test_no_change_is_a_neutral_dot(): void
    {
        $change = $this->change($this->band([$this->figure('trm', 'flat')]), 'trm');

        $this->assertStringContainsString('●', $change->textContent);
        $this->assertStringNotContainsString('▲', $change->textContent);
        $this->assertContains('flat', $this->classes($change));
    }

    public function test_the_arrow_is_hidden_and_the_sentence_is_read_instead(): void
    {
        $xpath = $this->band([$this->figure('trm', 'up')]);
        $change = $this->change($xpath, 'trm');

        $this->assertSame('true', $change->getAttribute('aria-hidden'));
        $this->assertStringContainsString('Subió 12,40 pesos (0,32 %)', $this->market($xpath, 'trm')->textContent);
    }

    public function test_the_repeated_lists_are_hidden_from_screen_readers(): void
    {
        $xpath = $this->band([$this->figure('trm', 'up'), $this->figure('eur', 'down')]);
        $lists = $xpath->query('//*[@data-markets]//ul');

        $this->assertGreaterThan(1, $lists->length);
        $this->assertFalse($lists->item(0)->hasAttribute('aria-hidden'));

        for ($i = 1; $i < $lists->length; $i++) {
            $copy = $lists->item($i);
            $this->assertSame('true', $copy->getAttribute('aria-hidden'));
            $this->assertStringContainsString('$3.861,38', $copy->textContent);
            $this->assertSame(0, $xpath->query('.//*[@data-market]', $copy)->length, 'A copy repeats no data-market.');
        }

        $this->assertSame(1, $xpath->query('//*[@data-market="trm"]')->length);
    }

    public function test_the_track_moves_one_list_per_lap(): void
    {
        $xpath = $this->band([$this->figure('trm', 'up'), $this->figure('eur', 'down')]);
        $track = $this->track($xpath);

        $this->assertMatchesRegularExpression('/--g-ticker-groups:\s*(\d+)/', $track->getAttribute('style'));
        preg_match('/--g-ticker-groups:\s*(\d+)/', $track->getAttribute('style'), $groups);
        $this->assertSame((int) $groups[1], $xpath->query('./ul', $track)->length);
    }

    public function test_the_lap_lasts_longer_with_more_figures(): void
    {
        $two = $this->seconds($this->band($this->figures(2)));
        $four = $this->seconds($this->band($this->figures(4)));

        $this->assertGreaterThan(0, $two);
        $this->assertSame($two * 2, $four);
    }

    public function test_any_number_of_codes_shows_each_once(): void
    {
        $xpath = $this->band($this->figures(5));

        foreach (['m0', 'm1', 'm2', 'm3', 'm4'] as $code) {
            $this->assertSame(1, $xpath->query("//*[@data-market=\"{$code}\"]")->length, $code);
        }
    }

    public function test_the_ticker_pauses_on_keyboard_focus(): void
    {
        $views = $this->band($this->figures(2))->query('//*[contains(concat(" ", normalize-space(@class), " "), " g-ticker-moves ")]');

        $this->assertSame(1, $views->length);
        $this->assertSame('0', $views->item(0)->getAttribute('tabindex'));
        $this->assertNotSame('', $views->item(0)->getAttribute('aria-label'));
    }

    public function test_a_moving_band_has_a_pause_control_for_touch_screens(): void
    {
        $xpath = $this->band($this->figures(2));
        $controls = $xpath->query('//*[@data-markets]//input[@data-market-pause]');

        $this->assertSame(1, $controls->length);
        $input = $controls->item(0);
        $this->assertSame('checkbox', $input->getAttribute('type'));
        $this->assertNotSame('-1', $input->getAttribute('tabindex'));
        $this->assertStringContainsString('Pausar', $input->getAttribute('aria-label'));
        $this->assertStringContainsString('reanudar', $input->getAttribute('aria-label'));

        $label = $input->parentNode;
        $this->assertSame('label', $label->nodeName);
        $this->assertStringContainsString('Pausar', $label->textContent);
        $this->assertStringContainsString('Reanudar', $label->textContent);
        $this->assertSame(0, $xpath->query('ancestor::*[@aria-hidden="true"]', $input)->length);
    }

    public function test_the_pause_control_stops_the_track_and_shows_focus(): void
    {
        $css = (string) file_get_contents(resource_path('css/glass.css'));

        $this->assertMatchesRegularExpression('/:has\(\.g-ticker-pause-input:checked\)\s+\.g-ticker-track\s*\{[^}]*animation-play-state:\s*paused/', $css);
        $this->assertMatchesRegularExpression('/\.g-ticker-pause:has\(:focus-visible\)\s*\{[^}]*outline:\s*2px solid/', $css);
        $this->assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion: reduce\)\s*\{[^@]*\.g-ticker-pause\s*\{[^}]*display:\s*none/',
            $css,
        );
    }

    public function test_a_still_band_has_no_pause_control(): void
    {
        $this->assertSame(0, $this->band([$this->figure('trm', 'up')])->query('//*[@data-market-pause]')->length);
    }

    public function test_the_spoken_change_keeps_pesos_for_the_trm_and_the_euro(): void
    {
        $this->assertSame('Subió 12,40 pesos (0,32 %)', $this->figure('trm', 'up')->spokenChange());
        $this->assertSame('Bajó 12,40 pesos (0,32 %)', $this->figure('eur', 'down')->spokenChange());
        $this->assertSame('Sin cambio frente al valor anterior', $this->figure('trm', 'flat')->spokenChange());
    }

    public function test_the_spoken_change_says_dollars_for_dollar_priced_codes(): void
    {
        $this->assertSame('Subió 12,40 dólares (0,32 %)', $this->figure('brent', 'up')->spokenChange());
    }

    public function test_the_spoken_change_says_points_for_an_unknown_code(): void
    {
        $this->assertSame('Bajó 12,40 puntos (0,32 %)', $this->figure('m9', 'down')->spokenChange());
    }

    public function test_a_single_figure_does_not_move(): void
    {
        $xpath = $this->band([$this->figure('trm', 'up')]);
        $band = $xpath->query('//*[@data-markets]')->item(0);

        $this->assertSame(0, $xpath->query('//*[contains(@class, "g-ticker-moves")]')->length);
        $this->assertSame(0, $xpath->query('//*[@aria-hidden="true"]//*[contains(., "$3.861,38")]')->length);
        $this->assertSame(1, $xpath->query('.//ul', $band)->length);
        $this->assertStringNotContainsString('--g-ticker', $xpath->document->saveHTML($band));
        $this->assertSame(0, $xpath->query('.//*[@tabindex]', $band)->length);
    }

    public function test_the_motion_stops_under_reduced_motion(): void
    {
        $css = (string) file_get_contents(resource_path('css/glass.css'));

        $this->assertMatchesRegularExpression('/\.g-ticker-moves\s+\.g-ticker-track\s*\{[^}]*animation:\s*g-ticker\b/', $css);
        $this->assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion: reduce\)\s*\{[^@]*\.g-ticker-moves \.g-ticker-track\s*\{[^}]*animation:\s*none/',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion: reduce\)\s*\{[^@]*\.g-ticker-group\[aria-hidden=.true.\]\s*\{[^}]*display:\s*none/',
            $css,
        );
    }

    public function test_the_built_css_carries_the_ticker(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
        $css = (string) file_get_contents(public_path('build/'.$manifest['resources/css/app.css']['file']));

        $this->assertStringContainsString('.g-ticker-moves', $css);
        $this->assertStringContainsString('@keyframes g-ticker', $css);
        $this->assertMatchesRegularExpression('/prefers-reduced-motion:\s*reduce\)\{[^@]*\.g-ticker-moves \.g-ticker-track\{[^}]*animation:none/', $css);
    }

    /**
     * @param  list<MarketFigure>  $figures
     */
    private function band(array $figures): \DOMXPath
    {
        $html = (string) $this->blade('<x-home.markets :figures="$figures" />', ['figures' => $figures]);
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);

        return new \DOMXPath($document);
    }

    private function market(\DOMXPath $xpath, string $code): \DOMElement
    {
        $nodes = $xpath->query("//*[@data-market=\"{$code}\"]");
        $this->assertSame(1, $nodes->length);

        return $nodes->item(0);
    }

    private function change(\DOMXPath $xpath, string $code): \DOMElement
    {
        $nodes = $xpath->query('.//*[@data-market-change]', $this->market($xpath, $code));
        $this->assertSame(1, $nodes->length);

        return $nodes->item(0);
    }

    private function track(\DOMXPath $xpath): \DOMElement
    {
        $nodes = $xpath->query('//*[@data-market-track]');
        $this->assertSame(1, $nodes->length);

        return $nodes->item(0);
    }

    private function seconds(\DOMXPath $xpath): int
    {
        $this->assertSame(1, preg_match('/--g-ticker-duration:\s*(\d+)s/', $this->track($xpath)->getAttribute('style'), $match));

        return (int) $match[1];
    }

    /**
     * @return list<string>
     */
    private function classes(\DOMElement $element): array
    {
        return preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
    }

    /**
     * @return list<MarketFigure>
     */
    private function figures(int $count): array
    {
        return array_map(fn (int $i) => $this->figure("m{$i}", 'up', "Mercado {$i}"), range(0, $count - 1));
    }

    private function figure(string $code, string $direction, ?string $label = null): MarketFigure
    {
        return new MarketFigure(
            code: $code,
            label: $label ?? (MarketFigure::LABELS[$code] ?? strtoupper($code)),
            value: '$3.861,38',
            direction: $direction,
            percent: $direction === 'flat' ? '0,00 %' : '0,32 %',
            change: $direction === 'flat' ? '0,00' : '12,40',
            validFromIso: '2026-11-03',
            validFromLabel: '3 nov',
        );
    }
}
