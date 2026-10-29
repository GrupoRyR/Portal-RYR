<?php

namespace Tests\Feature\Search;

use App\Http\Controllers\AnnouncementController;
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
        $this->assertSame(route('announcements.index').'#a-'.$pinned->id, $announcements[0]['href']);
        $this->assertSame(route('announcements.index').'#a-'.$own->id, $announcements[1]['href']);
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

    public function test_the_index_caps_the_announcements_and_links_each_to_its_page(): void
    {
        Announcement::factory()->count(HomeSearchIndex::ANNOUNCEMENT_LIMIT + 5)->sequence(fn ($sequence) => [
            'title' => 'Anuncio '.($sequence->index + 1),
            'starts_at' => now()->subHours($sequence->index + 1),
        ])->create();

        $announcements = $this->index()['announcements'];

        $this->assertCount(HomeSearchIndex::ANNOUNCEMENT_LIMIT, $announcements);
        $this->assertSame('Anuncio 1', $announcements[0]['title']);

        $firstOfPageTwo = $announcements[AnnouncementController::PER_PAGE];
        $id = Announcement::query()->where('title', $firstOfPageTwo['title'])->value('id');
        $this->assertSame(route('announcements.index', ['page' => 2]).'#a-'.$id, $firstOfPageTwo['href']);
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
