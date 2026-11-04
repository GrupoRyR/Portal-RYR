<?php

namespace Tests\Feature\News;

use App\Audit\AuditPresenter;
use App\Livewire\Admin\NewsManager;
use App\Models\Company;
use App\Models\NewsItem;
use App\Models\NewsSource;
use App\Models\User;
use App\News\HostResolver;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * A web page given instead of an RSS address (odd/tasks/observaciones.md
 * O3): Admin › Noticias says so, or uses the feed the page announces
 * (`<link rel="alternate">`), fetched through the same safe fetcher; the
 * hourly fetch does the same for sources already saved with a page.
 */
class NewsAutodiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'https://www.eltiempo.com/bogota';

    private const FEED = 'https://www.eltiempo.com/rss/bogota.xml';

    private Company $segurex;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->seed(RoleSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00', 'America/Bogota'));

        // Every host is public except *.internal.example.org.
        $this->app->instance(HostResolver::class, new class implements HostResolver
        {
            public function resolve(string $host): array
            {
                return str_ends_with($host, '.internal.example.org') ? ['10.0.0.9'] : ['93.184.216.34'];
            }
        });

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);
        $this->admin = User::factory()->microsoft($this->segurex)->create(['name' => 'Jhon Castro']);
        $this->admin->assignRole('super_admin');
    }

    // --- Admin › Noticias -------------------------------------------------------

    public function test_a_page_that_announces_its_feed_saves_the_feed_and_says_so(): void
    {
        Http::fake([
            self::PAGE => $this->page('<link rel="alternate" type="application/rss+xml" title="Bogotá" href="/rss/bogota.xml">'),
            self::FEED => Http::response($this->rss(), 200, ['Content-Type' => 'application/rss+xml']),
        ]);

        $this->manager()
            ->set('name', 'El Tiempo Bogotá')
            ->set('url', self::PAGE)
            ->call('create')
            ->assertHasNoErrors()
            ->assertSeeText('Se encontró el canal RSS: '.self::FEED);

        $this->assertSame(self::FEED, NewsSource::query()->sole()->url);
        Http::assertSentCount(2);
    }

    public function test_a_page_without_a_feed_is_refused_with_a_clear_message(): void
    {
        Http::fake([self::PAGE => $this->page('')]);

        $this->manager()
            ->set('name', 'El Tiempo Bogotá')
            ->set('url', self::PAGE)
            ->call('create')
            ->assertHasErrors(['url'])
            ->assertSeeText('Esta dirección es una página web, no un canal RSS');

        $this->assertSame(0, NewsSource::query()->count());
    }

    public function test_an_announced_feed_that_is_not_https_or_not_public_is_not_used(): void
    {
        Http::fake([
            self::PAGE => $this->page(
                '<link rel="alternate" type="application/rss+xml" href="http://www.eltiempo.com/rss.xml">'
                .'<link rel="alternate" type="application/rss+xml" href="https://feeds.internal.example.org/rss.xml">'
            ),
        ]);

        $this->manager()
            ->set('name', 'El Tiempo Bogotá')
            ->set('url', self::PAGE)
            ->call('create')
            ->assertHasErrors(['url']);

        // Only the page: the plain http link is dropped and the internal host is never requested.
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'internal.example.org'));
    }

    public function test_a_real_feed_is_saved_as_typed(): void
    {
        Http::fake([self::FEED => Http::response($this->rss())]);

        $this->manager()
            ->set('name', 'El Tiempo Bogotá')
            ->set('url', self::FEED)
            ->call('create')
            ->assertHasNoErrors()
            ->assertSeeText('Se agregó la fuente «El Tiempo Bogotá».');

        $this->assertSame(self::FEED, NewsSource::query()->sole()->url);
    }

    public function test_a_site_that_does_not_answer_is_saved_and_left_to_the_hourly_fetch(): void
    {
        Http::fake([self::FEED => Http::response('', 503)]);

        $this->manager()
            ->set('name', 'El Tiempo Bogotá')
            ->set('url', self::FEED)
            ->call('create')
            ->assertHasNoErrors();

        $this->assertSame(self::FEED, NewsSource::query()->sole()->url);
    }

    public function test_editing_to_a_page_finds_its_feed_too(): void
    {
        $source = NewsSource::factory()->for($this->segurex)->create(['name' => 'El Tiempo', 'url' => 'https://www.eltiempo.com/rss/colombia.xml']);
        Http::fake([
            self::PAGE => $this->page('<link rel="alternate" type="application/atom+xml" href="https://www.eltiempo.com/rss/bogota.xml">'),
            self::FEED => Http::response($this->rss()),
        ]);

        $this->manager()
            ->call('startEditing', $source->id)
            ->set('url', self::PAGE)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSeeText('Se encontró el canal RSS: '.self::FEED);

        $this->assertSame(self::FEED, $source->fresh()->url);
    }

    public function test_renaming_a_source_does_not_request_it(): void
    {
        $source = NewsSource::factory()->for($this->segurex)->create(['name' => 'El Tiempo', 'url' => self::FEED]);
        Http::fake();

        $this->manager()
            ->call('startEditing', $source->id)
            ->set('name', 'El Tiempo Bogotá')
            ->call('save')
            ->assertHasNoErrors();

        Http::assertNothingSent();
    }

    public function test_the_error_of_a_page_reads_clearly(): void
    {
        $this->assertSame('Esta dirección es una página web, no un canal RSS (html_page)', NewsManager::errorLabel('html_page'));
    }

    // --- portal:fetch-news ----------------------------------------------------

    public function test_a_saved_page_with_a_feed_is_switched_to_the_feed_and_audited(): void
    {
        $source = NewsSource::factory()->for($this->segurex)->create(['name' => 'El Tiempo Bogotá', 'url' => self::PAGE]);
        Http::fake([
            self::PAGE => $this->page('<link rel="alternate" type="application/rss+xml" href="/rss/bogota.xml">'),
            self::FEED => Http::response($this->rss()),
        ]);

        $this->artisan('portal:fetch-news')
            ->expectsOutputToContain('1 fuentes leídas, 0 con error')
            ->assertSuccessful();

        $source->refresh();
        $this->assertSame(self::FEED, $source->url);
        $this->assertNull($source->last_error_code);
        $this->assertSame(['Lluvias en Bogotá'], NewsItem::query()->pluck('title')->all());

        $activity = Activity::query()->where('log_name', NewsSource::AUDIT_LOG)->where('event', 'updated')->latest('id')->firstOrFail();
        $entry = app(AuditPresenter::class)->activity($activity);
        $this->assertSame('Sistema', $entry->who);
        $this->assertSame([['label' => 'URL', 'old' => self::PAGE, 'new' => self::FEED]], $entry->changes);
    }

    public function test_a_saved_page_without_a_feed_keeps_a_clear_error(): void
    {
        $source = NewsSource::factory()->for($this->segurex)->create(['url' => self::PAGE]);
        Http::fake([self::PAGE => $this->page('')]);

        $this->artisan('portal:fetch-news')
            ->expectsOutputToContain('0 fuentes leídas, 1 con error')
            ->assertSuccessful();

        $this->assertSame('html_page', $source->fresh()->last_error_code);
        $this->assertSame(self::PAGE, $source->fresh()->url);
    }

    public function test_a_page_served_as_text_html_without_a_doctype_is_a_page_too(): void
    {
        $source = NewsSource::factory()->for($this->segurex)->create(['url' => self::PAGE]);
        Http::fake([self::PAGE => Http::response('<div>hola</div>', 200, ['Content-Type' => 'text/html; charset=utf-8'])]);

        $this->artisan('portal:fetch-news')->assertSuccessful();

        $this->assertSame('html_page', $source->fresh()->last_error_code);
    }

    public function test_a_feed_served_as_text_html_still_reads(): void
    {
        $source = NewsSource::factory()->for($this->segurex)->create(['url' => self::FEED]);
        Http::fake([self::FEED => Http::response($this->rss(), 200, ['Content-Type' => 'text/html'])]);

        $this->artisan('portal:fetch-news')->assertSuccessful();

        $this->assertNull($source->fresh()->last_error_code);
        $this->assertSame(1, NewsItem::query()->count());
    }

    public function test_the_feed_of_another_source_in_the_same_tab_is_not_taken_twice(): void
    {
        NewsSource::factory()->for($this->segurex)->create(['url' => self::FEED, 'tab' => NewsSource::TAB_COLOMBIA, 'active' => false]);
        $page = NewsSource::factory()->for($this->segurex)->create(['url' => self::PAGE, 'tab' => NewsSource::TAB_COLOMBIA]);
        Http::fake([
            self::PAGE => $this->page('<link rel="alternate" type="application/rss+xml" href="/rss/bogota.xml">'),
            self::FEED => Http::response($this->rss()),
        ]);

        $this->artisan('portal:fetch-news')->assertSuccessful();

        $this->assertSame(self::PAGE, $page->fresh()->url);
        $this->assertSame('html_page', $page->fresh()->last_error_code);
    }

    private function manager(): Testable
    {
        return Livewire::actingAs($this->admin)->test(NewsManager::class);
    }

    private function page(string $head): PromiseInterface
    {
        return Http::response(
            "<!DOCTYPE html>\n<html lang=\"es\"><head><meta charset=\"utf-8\"><title>Bogotá</title>{$head}</head><body><p>Noticias</p></body></html>",
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }

    private function rss(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Bogotá</title>'
            .'<item><title>Lluvias en Bogotá</title><link>https://www.eltiempo.com/bogota/lluvias</link><pubDate>Wed, 04 Nov 2026 08:00:00 -0500</pubDate></item>'
            .'</channel></rss>';
    }
}
