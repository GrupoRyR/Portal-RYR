<?php

namespace Tests\Feature\Search;

use App\Models\Announcement;
use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\AppCategory;
use App\Models\Company;
use App\Models\User;
use App\Search\HomeSearchIndex;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The «Ctrl K» search of the home screen (odd/tasks/inicio-fase-1.md WU4):
 * an index embedded in the page with only what the viewer can already see.
 */
class HomeSearchIndexTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00', 'America/Bogota'));

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam']);
        $this->ana = User::factory()->microsoft($this->segurex)->create([
            'name' => 'Ana María Pérez',
            'email' => 'ana.perez@segurex.test',
        ]);
    }

    public function test_the_index_lists_only_the_viewers_openable_apps_linking_like_their_cards(): void
    {
        $category = AppCategory::factory()->create(['name' => 'Operaciones']);
        $camaras = $this->openableApp(['name' => 'Cámaras', 'description' => 'Monitoreo de seguridad', 'app_category_id' => $category->id]);
        $this->openableApp(['name' => 'App oculta', 'status' => App::STATUS_HIDDEN]);
        App::factory()->forCompanies($this->segurex)->create(['name' => 'App sin regla']);
        $other = Company::factory()->create();
        $foreign = App::factory()->forCompanies($other)->create(['name' => 'App de otra empresa']);
        AppAccessRule::forCompany($foreign, $other);

        $apps = $this->index()['apps'];

        $this->assertSame(['Cámaras'], array_column($apps, 'title'));
        $this->assertSame('Operaciones', $apps[0]['detail']);
        $this->assertSame('camaras monitoreo de seguridad', $apps[0]['search']);
        $this->assertSame(route('apps.open', $camaras), $apps[0]['href']);
    }

    public function test_the_index_lists_only_announcements_visible_to_the_viewers_company(): void
    {
        $pinned = Announcement::factory()->pinned()->create(['title' => 'Para todos', 'starts_at' => now()->subDays(3)]);
        $own = Announcement::factory()->forCompanies($this->segurex)->create(['title' => 'Solo Segurex', 'starts_at' => now()->subDay()]);
        Announcement::factory()->forCompanies(Company::factory()->create())->create(['title' => 'Para otra empresa']);
        Announcement::factory()->scheduled()->create(['title' => 'Programado']);
        Announcement::factory()->expired()->create(['title' => 'Vencido']);
        Announcement::factory()->withdrawn()->create(['title' => 'Retirado']);

        $announcements = $this->index()['announcements'];

        $this->assertSame(['Para todos', 'Solo Segurex'], array_column($announcements, 'title'));
        $this->assertSame(route('announcements.show', $pinned), $announcements[0]['href']);
        $this->assertSame(route('announcements.show', $own), $announcements[1]['href']);
    }

    public function test_announcements_carry_a_short_excerpt_and_never_their_body(): void
    {
        Announcement::factory()->create([
            'title' => 'Cierre de mes',
            'body' => "Recuerda   enviar\nlos soportes antes del viernes. ".str_repeat('Detalle largo del proceso. ', 8).'COLA-PRIVADA',
        ]);

        $response = $this->home();
        $announcement = $this->index($response)['announcements'][0];

        $this->assertStringStartsWith('Recuerda enviar los soportes antes del viernes.', $announcement['detail']);
        $this->assertLessThanOrEqual(HomeSearchIndex::EXCERPT_LENGTH + 3, mb_strlen($announcement['detail']));
        $this->assertArrayNotHasKey('body', $announcement);
        $this->assertStringNotContainsString('COLA-PRIVADA', $this->rawIndex($response));
    }

    public function test_the_index_caps_the_announcements_and_links_each_to_its_own_link(): void
    {
        Announcement::factory()->count(HomeSearchIndex::ANNOUNCEMENT_LIMIT + 5)->sequence(fn ($sequence) => [
            'title' => 'Anuncio '.($sequence->index + 1),
            'starts_at' => now()->subHours($sequence->index + 1),
        ])->create();

        $announcements = $this->index()['announcements'];

        $this->assertCount(HomeSearchIndex::ANNOUNCEMENT_LIMIT, $announcements);
        $this->assertSame('Anuncio 1', $announcements[0]['title']);

        // The link finds the page when it is followed (AnnouncementLinkTest),
        // so a saved copy of the home page never points at a stale page.
        $firstOfPageTwo = $announcements[Announcement::PER_PAGE];
        $id = Announcement::query()->where('title', $firstOfPageTwo['title'])->value('id');
        $this->assertSame(route('announcements.show', $id), $firstOfPageTwo['href']);
    }

    public function test_the_index_lists_the_holidays_of_the_next_twelve_months(): void
    {
        $dates = $this->index()['dates'];

        $this->assertSame('2026-11-02', $dates[0]['date']);
        $this->assertSame('Todos los Santos', $dates[0]['title']);
        $this->assertSame('Festivo · lunes 2 de noviembre', $dates[0]['detail']);
        $this->assertSame('todos los santos festivo lunes 2 de noviembre', $dates[0]['search']);
        $this->assertArrayNotHasKey('href', $dates[0]);

        $newYear = $dates[array_search('2027-01-01', array_column($dates, 'date'), true)];
        $this->assertSame('Festivo · viernes 1 de enero de 2027', $newYear['detail']);

        $this->assertSame('2027-10-18', end($dates)['date']);
        $this->assertCount(19, $dates);
    }

    public function test_viewers_without_a_company_get_no_announcements_like_the_announcements_page(): void
    {
        Announcement::factory()->create(['title' => 'Para todos']);
        $emergency = User::factory()->emergency()->create();
        $companyless = User::factory()->create(['company_id' => null]);

        foreach ([$emergency, $companyless] as $viewer) {
            $this->actingAs($viewer)->get(route('announcements.index'))
                ->assertOk()
                ->assertSeeText('No hay anuncios por ahora.');

            $index = $this->index($this->actingAs($viewer)->get('/')->assertOk());

            $this->assertSame([], $index['announcements']);
            $this->assertSame([], $index['apps']);
        }
    }

    /**
     * Today /anuncios lists announcements to the users of a deactivated
     * company (Announcement::scopeVisibleTo does not look at is_active),
     * while the catalog shows them no apps (AppCatalog::mayUseCatalog). The
     * index follows both pages: never more than they already show.
     */
    public function test_a_deactivated_companys_viewer_gets_what_the_announcements_page_shows(): void
    {
        $inactive = Company::factory()->inactive()->create();
        $viewer = User::factory()->microsoft($inactive)->create();
        $app = App::factory()->forCompanies($inactive)->create(['name' => 'App inactiva']);
        AppAccessRule::forCompany($app, $inactive);
        Announcement::factory()->create(['title' => 'Para todos', 'starts_at' => now()->subHour()]);
        Announcement::factory()->forCompanies($inactive)->create(['title' => 'Solo inactiva', 'starts_at' => now()->subDay()]);
        Announcement::factory()->forCompanies($this->segurex)->create(['title' => 'Solo Segurex']);

        $this->actingAs($viewer)->get(route('announcements.index'))
            ->assertOk()
            ->assertSeeTextInOrder(['Para todos', 'Solo inactiva'])
            ->assertDontSeeText('Solo Segurex');

        $index = $this->index($this->actingAs($viewer)->get('/')->assertOk());

        $this->assertSame(['Para todos', 'Solo inactiva'], array_column($index['announcements'], 'title'));
        $this->assertSame([], $index['apps']);
    }

    public function test_an_excerpt_cannot_break_out_of_the_index_script(): void
    {
        $body = '</script><img src=x onerror="alert(1)"> & \'comillas\'';
        Announcement::factory()->create(['title' => 'Aviso', 'body' => $body]);

        $response = $this->home();
        $raw = $this->rawIndex($response);

        foreach (['<', '>', '&', "'", 'onerror="'] as $character) {
            $this->assertStringNotContainsString($character, $raw);
        }
        $this->assertStringContainsString('onerror='.'\u0022alert(1)'.'\u0022', $raw);
        $this->assertSame($body, $this->index($response)['announcements'][0]['detail']);
    }

    public function test_one_invalid_utf8_string_does_not_break_the_index(): void
    {
        $index = new HomeSearchIndex(
            apps: [['title' => "Cartera \xB1", 'detail' => 'Finanzas', 'search' => 'cartera', 'href' => '/apps/cartera', 'newTab' => false]],
            announcements: [],
            dates: [],
        );

        $decoded = json_decode($index->toJson(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame("Cartera \u{FFFD}", $decoded['apps'][0]['title']);
    }

    public function test_the_index_holds_no_people(): void
    {
        User::factory()->microsoft($this->segurex)->create(['name' => 'Rodrigo Quiñónez', 'email' => 'rodrigo.quinonez@segurex.test']);
        $category = AppCategory::factory()->create(['name' => 'Finanzas']);
        $this->openableApp(['name' => 'Cartera', 'description' => 'Saldos de clientes', 'app_category_id' => $category->id]);
        Announcement::factory()->create(['title' => 'Bienvenida', 'body' => 'Nuevo portal de aplicaciones.']);

        $response = $this->home();
        $raw = $this->rawIndex($response);

        $this->assertSame(['apps', 'announcements', 'dates'], array_keys($this->index($response)));

        foreach (['Rodrigo', 'rodrigo', 'Quiñónez', 'quinonez', 'Ana María', 'Pérez', 'perez', 'segurex.test'] as $person) {
            $this->assertStringNotContainsString($person, $raw);
        }
    }

    public function test_a_title_cannot_break_out_of_the_index_script(): void
    {
        Announcement::factory()->create(['title' => '</script><script>alert(1)</script>']);

        $response = $this->home();

        $response->assertDontSee('</script><script>alert(1)', false);
        $this->assertSame('</script><script>alert(1)</script>', $this->index($response)['announcements'][0]['title']);
    }

    public function test_the_page_offers_an_accessible_search_dialog(): void
    {
        $this->openableApp();

        $this->home()
            ->assertSee('<dialog id="busqueda"', false)
            ->assertSee('aria-labelledby="busqueda-titulo"', false)
            ->assertSee('<label for="busqueda-texto"', false)
            ->assertSee('role="combobox"', false)
            ->assertSee('role="listbox"', false)
            ->assertSeeInOrder(['Aplicaciones', 'Anuncios', 'Fechas'])
            ->assertDontSee('livewire', false)
            ->assertDontSee('x-data', false);
    }

    public function test_a_server_search_still_filters_without_javascript(): void
    {
        $this->openableApp(['name' => 'Cámaras', 'description' => 'Monitoreo de seguridad']);
        $this->openableApp(['name' => 'Toma Pedidos SAP', 'description' => 'Pedidos de venta']);

        $this->home('?q=camaras')
            ->assertSeeText('Cámaras')
            ->assertDontSeeText('Toma Pedidos SAP')
            ->assertSeeText('1 resultado')
            ->assertDontSee('id="search-index"', false);

        $this->home()->assertSee('id="search-index"', false);
    }

    public function test_the_server_search_page_keeps_the_slash_hint(): void
    {
        $this->openableApp(['name' => 'Cámaras']);
        $hint = 'title="Pulsa / para buscar"';

        $this->home('?q=camaras')->assertSee($hint, false)->assertDontSee('data-search-open', false);
        $this->home()->assertDontSee($hint, false)->assertSee('data-search-open', false);
    }

    public function test_the_server_search_page_fetches_only_the_announcements_it_shows(): void
    {
        $this->openableApp(['name' => 'Cámaras']);
        Announcement::factory()->count(5)->create();
        $sql = [];
        DB::listen(function ($query) use (&$sql) {
            $sql[] = $query->sql;
        });

        $this->home('?q=camaras');

        $announcementQueries = array_values(array_filter($sql, fn (string $query) => str_contains($query, 'from "announcements"')));
        $this->assertCount(1, $announcementQueries);
        $this->assertStringContainsString('limit 3', $announcementQueries[0]);
    }

    public function test_the_index_adds_no_query(): void
    {
        $this->openableApp();
        Announcement::factory()->create();
        $few = $this->countQueries();

        Announcement::factory()->count(HomeSearchIndex::ANNOUNCEMENT_LIMIT + 5)->create();
        Announcement::factory()->forCompanies($this->segurex)->count(3)->create();
        foreach (range(1, 5) as $i) {
            $this->openableApp();
        }

        $many = $this->countQueries();

        $this->assertSame($few, $many);
        $this->assertLessThanOrEqual(12, $many);
    }

    private function home(string $suffix = ''): TestResponse
    {
        return $this->actingAs($this->ana->fresh())->get('/'.$suffix)->assertOk();
    }

    private function rawIndex(TestResponse $response): string
    {
        $found = preg_match('~<script type="application/json" id="search-index">(.*?)</script>~s', $response->getContent(), $match);
        $this->assertSame(1, $found, 'Expected the embedded search index.');

        return $match[1];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function index(?TestResponse $response = null): array
    {
        return json_decode($this->rawIndex($response ?? $this->home()), true, flags: JSON_THROW_ON_ERROR);
    }

    private function countQueries(): int
    {
        $user = $this->ana->fresh();
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $this->actingAs($user)->get('/')->assertOk();

        $result = $count;
        app('events')->forget('Illuminate\Database\Events\QueryExecuted');

        return $result;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function openableApp(array $attributes = []): App
    {
        $app = App::factory()->forCompanies($this->segurex)->create($attributes);
        AppAccessRule::forCompany($app, $this->segurex);

        return $app;
    }
}
