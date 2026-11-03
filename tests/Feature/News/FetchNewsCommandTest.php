<?php

namespace Tests\Feature\News;

use App\Models\Company;
use App\Models\NewsItem;
use App\Models\NewsSource;
use App\News\HostResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FetchNewsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00', 'America/Bogota'));

        // Every *.example.com host is public; *.internal.example.org is not.
        $this->app->instance(HostResolver::class, new class implements HostResolver
        {
            public function resolve(string $host): array
            {
                return str_ends_with($host, '.internal.example.org') ? ['10.0.0.9'] : ['93.184.216.34'];
            }
        });

        $this->company = Company::factory()->create();
    }

    private function source(array $attributes = []): NewsSource
    {
        return NewsSource::factory()->for($this->company)->create($attributes);
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2?: ?string, 3?: ?string}>  $items  title, link, pubDate, guid
     */
    private function rss(array $items): string
    {
        $xml = '';
        foreach ($items as $item) {
            $xml .= '<item><title>'.htmlspecialchars($item[0]).'</title><link>'.htmlspecialchars($item[1]).'</link>'
                .(isset($item[2]) ? '<pubDate>'.$item[2].'</pubDate>' : '')
                .(isset($item[3]) ? '<guid>'.htmlspecialchars($item[3]).'</guid>' : '')
                .'</item>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>T</title>'.$xml.'</channel></rss>';
    }

    public function test_each_active_source_stores_title_link_and_date(): void
    {
        $source = $this->source(['url' => 'https://news.example.com/rss']);
        Http::fake(['https://news.example.com/rss' => Http::response($this->rss([
            ['El peso se fortalece', 'https://news.example.com/peso', 'Wed, 04 Nov 2026 08:00:00 -0500', 'peso-1'],
            ['Lluvias en Bogotá', 'https://news.example.com/lluvias', 'Tue, 03 Nov 2026 08:00:00 -0500'],
        ]))]);

        $this->artisan('portal:fetch-news')->assertSuccessful();

        $items = $source->items()->orderByDesc('published_at')->get();
        $this->assertSame(['El peso se fortalece', 'Lluvias en Bogotá'], $items->pluck('title')->all());
        $this->assertSame('https://news.example.com/peso', $items[0]->link);
        $this->assertSame(hash('sha256', 'peso-1'), $items[0]->guid_hash);
        $this->assertSame(hash('sha256', 'https://news.example.com/lluvias'), $items[1]->guid_hash);
        $this->assertTrue($items[0]->published_at->equalTo(CarbonImmutable::parse('2026-11-04 13:00', 'UTC')));

        $source->refresh();
        $this->assertNull($source->last_error_code);
        $this->assertTrue($source->last_fetched_at->equalTo(now()));
    }

    public function test_only_title_link_and_date_are_kept_never_descriptions_or_html(): void
    {
        $this->assertSame(
            ['created_at', 'guid_hash', 'id', 'link', 'news_source_id', 'published_at', 'title', 'updated_at'],
            collect(Schema::getColumnListing('news_items'))->sort()->values()->all(),
        );
    }

    public function test_inactive_sources_are_not_fetched(): void
    {
        $this->source(['url' => 'https://news.example.com/off', 'active' => false]);
        Http::fake();

        $this->artisan('portal:fetch-news')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_failing_source_stores_its_code_and_never_stops_the_others(): void
    {
        $missing = $this->source(['url' => 'https://news.example.com/missing']);
        $xxe = $this->source(['url' => 'https://news.example.com/xxe']);
        $blocked = $this->source(['url' => 'https://feeds.internal.example.org/rss']);
        $huge = $this->source(['url' => 'https://news.example.com/huge']);
        $good = $this->source(['url' => 'https://news.example.com/good']);

        Http::fake([
            'https://news.example.com/missing' => Http::response('<html>secret error page</html>', 404),
            'https://news.example.com/xxe' => Http::response('<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss version="2.0"><channel><item><title>&x;</title><link>https://news.example.com/x</link></item></channel></rss>'),
            'https://news.example.com/huge' => Http::response(str_repeat(' ', 1_048_577)),
            'https://news.example.com/good' => Http::response($this->rss([['Bien', 'https://news.example.com/bien']])),
        ]);

        $this->artisan('portal:fetch-news')
            ->expectsOutputToContain('1 fuentes leídas, 4 con error')
            ->assertSuccessful();

        $this->assertSame('http_404', $missing->fresh()->last_error_code);
        $this->assertSame('doctype', $xxe->fresh()->last_error_code);
        $this->assertSame('blocked_address', $blocked->fresh()->last_error_code);
        $this->assertSame('too_large', $huge->fresh()->last_error_code);
        $this->assertNull($good->fresh()->last_error_code);
        $this->assertSame(['Bien'], NewsItem::query()->pluck('title')->all());
        $this->assertTrue($missing->fresh()->last_fetched_at->equalTo(now()), 'A failed attempt is recorded too.');
    }

    public function test_a_later_success_clears_the_error_code_and_keeps_old_items_on_failure(): void
    {
        $source = $this->source(['url' => 'https://news.example.com/rss']);
        Http::fake(['https://news.example.com/rss' => Http::sequence()
            ->push($this->rss([['Primera', 'https://news.example.com/1']]))
            ->push('', 500)
            ->push($this->rss([['Segunda', 'https://news.example.com/2']]))]);

        $this->artisan('portal:fetch-news');
        $this->artisan('portal:fetch-news');
        $this->assertSame('http_500', $source->fresh()->last_error_code);
        $this->assertSame(['Primera'], $source->items()->pluck('title')->all());

        $this->artisan('portal:fetch-news');
        $this->assertNull($source->fresh()->last_error_code);
        $this->assertEqualsCanonicalizing(['Primera', 'Segunda'], $source->items()->pluck('title')->all());
    }

    public function test_an_item_seen_again_is_updated_not_duplicated(): void
    {
        $source = $this->source(['url' => 'https://news.example.com/rss']);
        Http::fake(['https://news.example.com/rss' => Http::sequence()
            ->push($this->rss([['Título viejo', 'https://news.example.com/a', null, 'g-1'], ['Repetido', 'https://news.example.com/b', null, 'g-1']]))
            ->push($this->rss([['Título nuevo', 'https://news.example.com/a2', null, 'g-1']]))]);

        $this->artisan('portal:fetch-news');
        $this->assertSame(1, $source->items()->count());
        $this->assertSame('Título viejo', $source->items()->value('title'));

        $this->artisan('portal:fetch-news');
        $this->assertSame(1, $source->items()->count());
        $this->assertSame('Título nuevo', $source->items()->value('title'));
        $this->assertSame('https://news.example.com/a2', $source->items()->value('link'));
    }

    public function test_only_the_newest_30_items_per_source_are_kept(): void
    {
        $source = $this->source(['url' => 'https://news.example.com/rss']);
        $other = $this->source(['url' => 'https://news.example.com/other']);
        $items = [];
        for ($i = 1; $i <= 40; $i++) {
            $items[] = ["Noticia {$i}", "https://news.example.com/{$i}", now()->subHours($i)->toRfc2822String()];
        }
        Http::fake([
            'https://news.example.com/rss' => Http::response($this->rss($items)),
            'https://news.example.com/other' => Http::response($this->rss([['Otra', 'https://news.example.com/otra']])),
        ]);

        $this->artisan('portal:fetch-news');

        $this->assertSame(30, $source->items()->count());
        $this->assertSame('Noticia 1', $source->items()->orderByDesc('published_at')->value('title'));
        $this->assertSame('Noticia 30', $source->items()->orderBy('published_at')->value('title'));
        $this->assertSame(1, $other->items()->count());
    }

    public function test_items_older_than_60_days_are_deleted_and_never_stored(): void
    {
        $source = $this->source(['url' => 'https://news.example.com/rss']);
        $stale = NewsItem::factory()->for($source, 'source')->create(['published_at' => now()->subDays(61)]);
        $recent = NewsItem::factory()->for($source, 'source')->create(['published_at' => now()->subDays(59)]);
        $inactive = $this->source(['active' => false]);
        $staleOfInactive = NewsItem::factory()->for($inactive, 'source')->create(['published_at' => now()->subDays(70)]);

        Http::fake(['https://news.example.com/rss' => Http::response($this->rss([
            ['Vieja', 'https://news.example.com/vieja', now()->subDays(90)->toRfc2822String()],
            ['Nueva', 'https://news.example.com/nueva', now()->subDay()->toRfc2822String()],
        ]))]);

        $this->artisan('portal:fetch-news');

        $this->assertModelMissing($stale);
        $this->assertModelMissing($staleOfInactive);
        $this->assertModelExists($recent);
        $this->assertFalse($source->items()->where('title', 'Vieja')->exists());
        $this->assertTrue($source->items()->where('title', 'Nueva')->exists());
    }

    public function test_missing_dates_use_the_fetch_time_and_future_dates_are_capped(): void
    {
        $source = $this->source(['url' => 'https://news.example.com/rss']);
        Http::fake(['https://news.example.com/rss' => Http::response($this->rss([
            ['Sin fecha', 'https://news.example.com/a'],
            ['Del futuro', 'https://news.example.com/b', 'Fri, 01 Jan 2100 00:00:00 +0000'],
        ]))]);

        $this->artisan('portal:fetch-news');

        foreach ($source->items as $item) {
            $this->assertTrue($item->published_at->equalTo(now()), $item->title);
        }
    }

    public function test_the_fetch_runs_every_hour_in_bogota_without_overlapping(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'portal:fetch-news'));

        $this->assertNotNull($event, 'portal:fetch-news is not scheduled.');
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertSame('America/Bogota', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }
}
