<?php

namespace Tests\Feature\Home;

use App\Models\Announcement;
use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\AppLaunch;
use App\Models\Company;
use App\Models\User;
use App\View\Home\QuickLinks;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The «Mi día» column of the home screen (upcoming holidays, announcements
 * and Microsoft 365 links), the next holiday beside the date line and the
 * «Secciones» menu of phones. The holiday data: HomeUpcomingHolidaysTest.
 */
class HomeMyDayTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['name' => 'Segurex Latam']);
        $this->user = User::factory()->microsoft($this->company)->create(['name' => 'Ana María Pérez']);
    }

    public function test_two_holidays_on_the_same_day_show_both_names(): void
    {
        $this->travelTo(CarbonImmutable::parse('2025-06-20 10:00', 'America/Bogota'));
        $fechas = $this->section($this->home(), 'id', 'fechas');

        $this->assertStringContainsString('Próximas fechas', $fechas);
        $this->assertStringContainsString('Sagrado Corazón', $fechas);
        $this->assertStringContainsString('San Pedro y San Pablo', $fechas);
        $this->assertStringContainsString('datetime="2025-06-30"', $fechas);
    }

    public function test_the_date_line_names_the_next_holiday_in_bogota_time(): void
    {
        // 04:59 UTC on 8 December is still 23:59 on the 7th in Bogotá.
        $this->travelTo(CarbonImmutable::parse('2026-12-08 04:59', 'UTC'));

        $this->home()
            ->assertSeeText('Lunes, 7 de diciembre')
            ->assertSeeText('Próximo festivo: martes 8 de diciembre, Inmaculada Concepción')
            ->assertDontSeeText('Hoy es festivo');
    }

    public function test_the_date_line_says_when_today_is_a_holiday(): void
    {
        // 05:00 UTC is midnight in Bogotá.
        $this->travelTo(CarbonImmutable::parse('2026-12-08 05:00', 'UTC'));

        $this->home()
            ->assertSeeText('Martes, 8 de diciembre')
            ->assertSeeText('Hoy es festivo: Inmaculada Concepción')
            ->assertDontSeeText('Próximo festivo');
    }

    public function test_the_my_day_column_holds_the_dates_the_announcements_and_microsoft_365(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00', 'America/Bogota'));
        $this->openableApp();
        Announcement::factory()->create(['title' => 'Jornada de vacunación', 'starts_at' => now()->subHour()]);
        Announcement::factory()->forCompanies(Company::factory()->create())->create(['title' => 'Para otra empresa', 'starts_at' => now()->subHour()]);

        $response = $this->home();
        $myDay = $this->section($response, 'id', 'mi-dia');

        $this->assertStringContainsString('aria-label="Mi día"', $myDay);
        $this->assertStringContainsString('id="fechas"', $myDay);
        $this->assertStringContainsString('Todos los Santos', $myDay);
        $this->assertStringContainsString('data-announcements', $myDay);
        $this->assertStringContainsString('Jornada de vacunación', $myDay);
        $this->assertStringContainsString('Microsoft 365', $myDay);
        $this->assertStringContainsString('href="'.QuickLinks::DEFAULTS['Outlook'].'"', $myDay);
        $response->assertDontSeeText('Para otra empresa');

        // The phone banner keeps the top announcement above the apps.
        $this->assertStringContainsString('Jornada de vacunación', $this->section($response, 'data-announcement-banner'));
    }

    public function test_there_is_no_view_as_selector(): void
    {
        $this->openableApp();

        $this->home()->assertDontSeeText('Ver como')->assertDontSee('livewire', false)->assertDontSee('x-data', false);
    }

    public function test_the_sections_menu_links_only_to_sections_on_the_page(): void
    {
        $this->openableApp();
        $this->assertSectionsMenuLinksResolve($this->home(), ['#contenido', '#favoritos', '#aplicaciones', '#fechas', '#m365']);
    }

    public function test_there_is_no_sections_menu_without_apps(): void
    {
        // The page is short, and the menu would crowd the company name off the logo row.
        $user = User::factory()->microsoft(Company::factory()->create())->create();
        $response = $this->actingAs($user)->get('/')->assertOk();

        $this->assertSame(0, $this->xpath($response)->query('//details[@data-sections]')->length);
        $response->assertDontSeeText('Secciones');
    }

    public function test_the_sections_menu_skips_sections_hidden_by_a_search(): void
    {
        $favorite = $this->openableApp(['name' => 'Nómina', 'description' => 'Desprendibles de pago']);
        $this->user->favoriteApps()->attach($favorite);
        $this->openableApp(['name' => 'Cartera', 'description' => 'Cuentas por cobrar']);

        // No match at all: neither Favoritos nor Aplicaciones are on screen.
        $this->assertSectionsMenuLinksResolve($this->home('?q=zzzz'), ['#contenido', '#fechas', '#m365']);
        // Only a category matches.
        $this->assertSectionsMenuLinksResolve($this->home('?q=cartera'), ['#contenido', '#aplicaciones', '#fechas', '#m365']);
        // Only a favorite matches.
        $this->assertSectionsMenuLinksResolve($this->home('?q=nomina'), ['#contenido', '#favoritos', '#fechas', '#m365']);
    }

    public function test_holiday_dates_are_marked_for_the_saved_copy(): void
    {
        // resources/js/home.js corrects the copy the service worker replays on a later day.
        $this->travelTo(CarbonImmutable::parse('2026-12-08 10:00', 'America/Bogota'));
        $xpath = $this->xpath($this->home());

        $notice = $xpath->query('//*[@data-holiday-notice]');
        $this->assertSame(1, $notice->length);
        $this->assertSame('2026-12-08', $notice->item(0)->getAttribute('data-holiday-date'));
        $this->assertStringContainsString('Hoy es festivo', $notice->item(0)->textContent);

        $rows = $xpath->query('//section[@id="fechas"]//li');
        $dates = [];
        foreach ($rows as $row) {
            $dates[] = $row->getAttribute('data-holiday-date');
        }
        $this->assertSame(['2026-12-08', '2026-12-25', '2027-01-01', '2027-01-11'], $dates);

        // Today's row says «Hoy» and keeps the weekday for a later day.
        $today = $rows->item(0);
        $this->assertSame(1, $xpath->query('.//*[@data-holiday-today]', $today)->length);
        $weekday = $xpath->query('.//*[@data-holiday-weekday]', $today)->item(0);
        $this->assertSame('Hoy', $weekday->textContent);
        $this->assertSame('Martes', $weekday->getAttribute('data-holiday-weekday'));
        $this->assertSame(0, $xpath->query('.//*[@data-holiday-today]', $rows->item(1))->length);
    }

    public function test_the_sections_menu_lists_recents_when_there_are_some(): void
    {
        $app = $this->openableApp();
        AppLaunch::create([
            'user_id' => $this->user->id,
            'app_id' => $app->id,
            'company_id' => $this->company->id,
            'device' => 'phone',
            'launched_at' => now()->subMinutes(5),
        ]);

        $this->assertSectionsMenuLinksResolve($this->home(), ['#contenido', '#favoritos', '#recientes', '#aplicaciones', '#fechas', '#m365']);
    }

    /**
     * @param  list<string>  $expected
     */
    private function assertSectionsMenuLinksResolve(TestResponse $response, array $expected): void
    {
        $xpath = $this->xpath($response);
        $menus = $xpath->query('//details[@data-sections]');
        $this->assertSame(1, $menus->length, 'Expected one «Secciones» menu.');
        $this->assertStringContainsString('Secciones', $menus->item(0)->textContent);

        $hrefs = [];
        foreach ($xpath->query('.//a', $menus->item(0)) as $link) {
            $href = $link->getAttribute('href');
            $hrefs[] = $href;
            $this->assertStringStartsWith('#', $href);
            $this->assertSame(1, $xpath->query('//*[@id="'.substr($href, 1).'"]')->length, "{$href} is not on the page.");
        }

        $this->assertSame($expected, $hrefs);
    }

    private function home(string $query = ''): TestResponse
    {
        return $this->actingAs($this->user->fresh())->get('/'.$query)->assertOk();
    }

    private function xpath(TestResponse $response): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8"?>'.$response->getContent());

        return new \DOMXPath($document);
    }

    /**
     * The markup of the single element whose attribute (optionally with a value) matches.
     */
    private function section(TestResponse $response, string $attribute, ?string $value = null): string
    {
        $xpath = $this->xpath($response);
        $nodes = $xpath->query($value === null ? "//*[@{$attribute}]" : "//*[@{$attribute}=\"{$value}\"]");

        $this->assertSame(1, $nodes->length, "Expected one element with {$attribute}.");

        return html_entity_decode((string) $xpath->document->saveHTML($nodes->item(0)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function openableApp(array $attributes = []): App
    {
        $app = App::factory()->forCompanies($this->company)->create($attributes);
        AppAccessRule::forCompany($app, $this->company);

        return $app;
    }
}
