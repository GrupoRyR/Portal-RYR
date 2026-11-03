<?php

namespace Tests\Feature\Home;

use App\Models\Company;
use App\Models\MarketRate;
use App\Models\NewsItem;
use App\Models\NewsSource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Home\Concerns\ReadsTheHome;
use Tests\TestCase;

/**
 * The «Mercados» band (App\Markets\MarketBoard) and the «Noticias» block
 * (App\News\NewsBoard) of the home screen (odd/tasks/inicio-bloques.md H1).
 */
class HomeMarketsAndNewsTest extends TestCase
{
    use ReadsTheHome;
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00', 'America/Bogota'));
        $this->company = Company::factory()->create(['name' => 'Segurex Latam']);
        $this->user = User::factory()->microsoft($this->company)->create(['name' => 'Ana Pérez']);
        $this->openableApp();
    }

    // --- Mercados ------------------------------------------------------------

    public function test_there_is_no_market_band_before_the_first_sync(): void
    {
        $this->assertNoElement($this->home(), 'data-markets');
    }

    public function test_the_band_shows_the_trm_and_the_euro_with_their_change(): void
    {
        MarketRate::factory()->trm()->create(['value' => 3848.98, 'valid_from' => '2026-11-02']);
        MarketRate::factory()->trm()->create(['value' => 3861.38, 'valid_from' => '2026-11-03']);
        MarketRate::factory()->eur()->create(['value' => 4500.00, 'valid_from' => '2026-11-02']);
        MarketRate::factory()->eur()->create(['value' => 4478.12, 'valid_from' => '2026-11-03']);

        $response = $this->home();
        $band = $this->element($response, 'data-markets');

        $this->assertStringContainsString('Mercados', $band);
        $trm = $this->element($response, 'data-market', 'trm');
        $this->assertStringContainsString('Dólar (TRM)', $trm);
        $this->assertStringContainsString('$3.861,38', $trm);
        $this->assertStringContainsString('Subió 12,40 pesos (0,32 %)', $trm);
        $this->assertStringContainsString('datetime="2026-11-03"', $trm);

        $euro = $this->element($response, 'data-market', 'eur');
        $this->assertStringContainsString('Euro', $euro);
        $this->assertStringContainsString('$4.478,12', $euro);
        $this->assertStringContainsString('Bajó 21,88 pesos (0,49 %)', $euro);
    }

    public function test_a_figure_without_a_previous_value_shows_no_change(): void
    {
        MarketRate::factory()->trm()->create(['value' => 3861.38, 'valid_from' => '2026-11-03']);

        $response = $this->home();
        $trm = $this->element($response, 'data-market', 'trm');

        $this->assertStringContainsString('$3.861,38', $trm);
        $this->assertStringNotContainsString('Subió', $trm);
        $this->assertStringNotContainsString('Bajó', $trm);
        $this->assertNoElement($response, 'data-market', 'eur');
    }

    // --- Noticias ------------------------------------------------------------

    public function test_there_is_no_news_block_without_headlines(): void
    {
        NewsSource::factory()->create(['company_id' => $this->company->id]);

        $response = $this->home();

        $this->assertNoElement($response, 'id', 'noticias');
        $this->assertNotContains('#noticias', $this->sectionLinks($response));
    }

    public function test_only_the_headlines_of_the_users_company_and_active_sources_show(): void
    {
        $this->headline(NewsSource::TAB_COLOMBIA, 'Banco de la República mantiene tasas', ['name' => 'El Tiempo']);
        $this->headline(NewsSource::TAB_COLOMBIA, 'Titular de una fuente apagada', ['active' => false]);
        $other = Company::factory()->create();
        $this->headline(NewsSource::TAB_COLOMBIA, 'Titular de otra empresa', ['company_id' => $other->id]);

        $response = $this->home();
        $news = $this->element($response, 'id', 'noticias');

        $this->assertStringContainsString('Noticias', $news);
        $this->assertStringContainsString('Banco de la República mantiene tasas', $news);
        $this->assertStringContainsString('El Tiempo', $news);
        $this->assertStringNotContainsString('Titular de una fuente apagada', $news);
        $this->assertStringNotContainsString('Titular de otra empresa', $news);
        $this->assertContains('#noticias', $this->sectionLinks($response));
    }

    public function test_headlines_open_outside_the_portal(): void
    {
        $this->headline(NewsSource::TAB_COLOMBIA, 'Titular', [], ['link' => 'https://www.eltiempo.com/nota-1']);

        $news = $this->element($this->home(), 'id', 'noticias');

        $this->assertStringContainsString('href="https://www.eltiempo.com/nota-1"', $news);
        $this->assertStringContainsString('target="_blank"', $news);
        $this->assertStringContainsString('rel="noopener noreferrer"', $news);
    }

    public function test_only_tabs_with_headlines_show_and_the_first_one_is_selected(): void
    {
        $this->headline(NewsSource::TAB_INTERNATIONAL, 'Titular internacional');
        $this->headline(NewsSource::TAB_SECTOR, 'Titular del sector');

        $response = $this->home();

        $this->assertNoElement($response, 'data-news-tab', NewsSource::TAB_COLOMBIA);
        $international = $this->element($response, 'data-news-tab', NewsSource::TAB_INTERNATIONAL);
        $this->assertStringContainsString('aria-selected="true"', $international);
        $this->assertStringContainsString('href="'.route('home', ['noticias' => 'internacional']).'#noticias"', $international);
        $this->assertStringContainsString('aria-selected="false"', $this->element($response, 'data-news-tab', NewsSource::TAB_SECTOR));

        $this->assertStringNotContainsString(' hidden', $this->openingTag($this->element($response, 'data-news-panel', NewsSource::TAB_INTERNATIONAL)));
        $this->assertStringContainsString(' hidden', $this->openingTag($this->element($response, 'data-news-panel', NewsSource::TAB_SECTOR)));
    }

    public function test_the_tab_in_the_address_is_selected_without_javascript(): void
    {
        $this->headline(NewsSource::TAB_COLOMBIA, 'Titular de Colombia');
        $this->headline(NewsSource::TAB_SECTOR, 'Titular del sector');

        $response = $this->home('?noticias=sector');

        $this->assertStringContainsString('aria-selected="true"', $this->element($response, 'data-news-tab', NewsSource::TAB_SECTOR));
        $this->assertStringContainsString(' hidden', $this->openingTag($this->element($response, 'data-news-panel', NewsSource::TAB_COLOMBIA)));
        $this->assertStringNotContainsString(' hidden', $this->openingTag($this->element($response, 'data-news-panel', NewsSource::TAB_SECTOR)));
    }

    public function test_an_unknown_or_empty_tab_in_the_address_falls_back_to_the_first(): void
    {
        $this->headline(NewsSource::TAB_COLOMBIA, 'Titular de Colombia');

        foreach (['?noticias=sector', '?noticias=nada', '?noticias[]=x'] as $suffix) {
            $this->assertStringContainsString(
                'aria-selected="true"',
                $this->element($this->home($suffix), 'data-news-tab', NewsSource::TAB_COLOMBIA),
                $suffix,
            );
        }
    }

    public function test_a_server_search_leaves_the_news_out(): void
    {
        $this->headline(NewsSource::TAB_COLOMBIA, 'Titular de Colombia');

        $this->assertNoElement($this->home('?q=zzz'), 'id', 'noticias');
    }

    public function test_an_account_without_a_company_sees_no_news(): void
    {
        $this->headline(NewsSource::TAB_COLOMBIA, 'Titular de Colombia');
        $this->user->forceFill(['company_id' => null])->save();

        $this->assertNoElement($this->home(), 'id', 'noticias');
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $item
     */
    private function headline(string $tab, string $title, array $source = [], array $item = []): NewsItem
    {
        $source = NewsSource::factory()->tab($tab)->create(['company_id' => $this->company->id, ...$source]);

        return NewsItem::factory()->create(['news_source_id' => $source->id, 'title' => $title, ...$item]);
    }

    private function openingTag(string $markup): string
    {
        return substr($markup, 0, (int) strpos($markup, '>') + 1);
    }
}
