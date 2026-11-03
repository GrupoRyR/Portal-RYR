<?php

namespace Tests\Feature\News;

use App\Models\Company;
use App\Models\NewsItem;
use App\Models\NewsSource;
use App\News\NewsBoard;
use App\News\NewsHeadline;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NewsBoardTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00', 'America/Bogota'));
        $this->company = Company::factory()->create();
    }

    private function source(string $tab, array $attributes = [], ?Company $company = null): NewsSource
    {
        return NewsSource::factory()->for($company ?? $this->company)->tab($tab)->create($attributes);
    }

    private function item(NewsSource $source, string $title, int $hoursAgo): NewsItem
    {
        return NewsItem::factory()->for($source, 'source')->create([
            'title' => $title,
            'link' => 'https://news.example.com/'.md5($title),
            'published_at' => now()->subHours($hoursAgo),
        ]);
    }

    public function test_each_tab_gets_its_newest_five_across_its_sources(): void
    {
        $eltiempo = $this->source(NewsSource::TAB_COLOMBIA, ['name' => 'El Tiempo']);
        $semana = $this->source(NewsSource::TAB_COLOMBIA, ['name' => 'Semana']);
        foreach (range(1, 4) as $i) {
            $this->item($eltiempo, "Tiempo {$i}", $i * 2);
            $this->item($semana, "Semana {$i}", $i * 2 + 1);
        }
        $this->item($this->source(NewsSource::TAB_SECTOR, ['name' => 'Seguridad al día']), 'Norma nueva', 30);

        $board = (new NewsBoard)->forCompany($this->company->id);

        $this->assertSame(['colombia', 'internacional', 'sector'], array_keys($board));
        $this->assertSame(
            ['Tiempo 1', 'Semana 1', 'Tiempo 2', 'Semana 2', 'Tiempo 3'],
            array_map(fn (NewsHeadline $headline) => $headline->title, $board['colombia']),
        );
        $this->assertSame([], $board['internacional']);
        $this->assertCount(1, $board['sector']);

        $first = $board['colombia'][0];
        $this->assertSame('El Tiempo', $first->source);
        $this->assertSame('https://news.example.com/'.md5('Tiempo 1'), $first->link);
        $this->assertTrue($first->publishedAt->equalTo(now()->subHours(2)));
    }

    public function test_inactive_sources_and_other_companies_are_left_out(): void
    {
        $this->item($this->source(NewsSource::TAB_COLOMBIA, ['active' => false]), 'Fuente apagada', 1);
        $this->item($this->source(NewsSource::TAB_COLOMBIA, [], Company::factory()->create()), 'Otra empresa', 1);
        $this->item($this->source(NewsSource::TAB_COLOMBIA), 'Propia', 5);

        $board = (new NewsBoard)->forCompany($this->company->id);

        $this->assertSame(['Propia'], array_map(fn (NewsHeadline $headline) => $headline->title, $board['colombia']));
    }

    public function test_the_whole_board_is_read_with_one_query(): void
    {
        foreach (array_keys(NewsSource::TABS) as $tab) {
            foreach (range(1, 3) as $s) {
                $source = $this->source($tab);
                foreach (range(1, 8) as $i) {
                    $this->item($source, "{$tab} {$s} {$i}", $s + $i);
                }
            }
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $board = (new NewsBoard)->forCompany($this->company->id);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
        foreach ($board as $headlines) {
            $this->assertCount(5, $headlines);
        }
    }

    public function test_a_company_without_sources_gets_three_empty_tabs(): void
    {
        $this->assertSame(
            ['colombia' => [], 'internacional' => [], 'sector' => []],
            (new NewsBoard)->forCompany($this->company->id),
        );
    }
}
