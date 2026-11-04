<?php

namespace Tests\Feature\Layouts;

use App\Http\Controllers\People\PeopleController;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\DirectoryEntry;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /anuncios, /eventos and /personas in Liquid Glass (odd/tasks/liquid-glass.md
 * G9): a title banner, then each list as rows inside one g-card (never a
 * blurred card per item), with its pager and notes in the card, so no
 * text sits straight on the photo. Data and anchors are the page tests'.
 */
class GlassListPagesTest extends TestCase
{
    use InspectsGlassPages, RefreshDatabase;

    private Company $segurex;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00', 'America/Bogota'));
        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam']);
        $this->ana = User::factory()->microsoft($this->segurex)->create();
    }

    public function test_the_announcements_are_rows_of_one_glass_card_with_the_pager_inside(): void
    {
        Announcement::factory()->forCompanies($this->segurex)->count(Announcement::PER_PAGE + 1)->create();

        $page = $this->xpathOf($this->actingAs($this->ana)->withSession(['status' => 'Confirmaste la lectura.'])->get(route('announcements.index'))->assertOk());

        $this->assertGlassPage($page, '/anuncios');
        $this->assertSame(Announcement::PER_PAGE, $page->query('//main//*['.self::hasClass('g-card').']//ol/li[starts-with(@id, "a-")]')->length);
        $this->assertSame(0, $page->query('//main//li['.self::hasClass('g-card').']')->length);
        $this->assertSame(1, $page->query('//main//*['.self::hasClass('g-card').']//nav[@aria-label="Páginas de anuncios"]//a[@rel="next"]['.self::hasClass('g-btn').']')->length);
        $this->assertSame(1, $page->query('//main//p[@role="status"]['.self::hasClass('g-card').']')->length);
    }

    public function test_a_page_past_the_end_still_offers_the_way_back(): void
    {
        Announcement::factory()->forCompanies($this->segurex)->count(Announcement::PER_PAGE + 1)->create();
        DirectoryEntry::factory()->forCompany($this->segurex)->count(PeopleController::PER_PAGE + 1)->create();

        $announcements = $this->xpathOf($this->actingAs($this->ana)->get(route('announcements.index', ['page' => 3]))->assertOk());
        $people = $this->xpathOf($this->actingAs($this->ana)->get(route('people.index', ['page' => 3]))->assertOk());

        $this->assertSame(1, $announcements->query('//main//*['.self::hasClass('g-card').']//nav[@aria-label="Páginas de anuncios"]//a[@rel="prev"]['.self::hasClass('g-btn').']')->length);
        $this->assertSame(1, $people->query('//main//*['.self::hasClass('g-card').']//nav[@aria-label="Páginas de personas"]//a[@rel="prev"]['.self::hasClass('g-btn').']')->length);
    }

    public function test_an_empty_list_says_so_on_glass(): void
    {
        $announcements = $this->xpathOf($this->actingAs($this->ana)->get(route('announcements.index'))->assertOk());
        $events = $this->xpathOf($this->actingAs($this->ana)->get(route('events.index'))->assertOk());

        $this->assertSame(1, $announcements->query('//main//*['.self::hasClass('g-card').'][contains(., "No hay anuncios por ahora.")]')->length);
        $this->assertSame(1, $events->query('//main//*['.self::hasClass('g-card').'][contains(., "No hay eventos próximos.")]')->length);
    }

    public function test_the_events_are_rows_with_a_yearly_pill_and_a_calendar_button(): void
    {
        $yearly = Event::factory()->allDay('2026-11-05')->yearly()->create(['title' => 'Aniversario']);
        $once = Event::factory()->create(['title' => 'Jornada de salud', 'starts_at' => '2026-10-30 09:00:00', 'ends_at' => '2026-10-30 11:00:00']);

        $page = $this->xpathOf($this->actingAs($this->ana)->get(route('events.index'))->assertOk());

        $this->assertGlassPage($page, '/eventos');
        $this->assertSame(2, $page->query('//main//*['.self::hasClass('g-card').']//ol/li[starts-with(@id, "e-")]')->length);
        $this->assertSame(0, $page->query('//main//li['.self::hasClass('g-card').']')->length);
        $this->assertSame(1, $page->query('//*[@id="e-'.$yearly->id.'"]//*['.self::hasClass('g-pill').'][normalize-space(.)="Cada año"]')->length);
        $this->assertSame(0, $page->query('//*[@id="e-'.$once->id.'"]//*['.self::hasClass('g-pill').']')->length);
        $this->assertSame(1, $page->query('//*[@id="e-'.$yearly->id.'"]//a[@href="'.route('events.calendar', $yearly).'"][@download]['.self::hasClass('g-btn').']')->length);
    }

    public function test_the_people_search_and_list_are_on_glass(): void
    {
        DirectoryEntry::factory()->forCompany($this->segurex)->count(PeopleController::PER_PAGE + 1)->create();

        $page = $this->xpathOf($this->actingAs($this->ana)->get(route('people.index'))->assertOk());

        $this->assertGlassPage($page, '/personas');
        $form = $page->query('//main//*['.self::hasClass('g-card').']//form[@role="search"][@method="GET"][@action="'.route('people.index').'"]');
        $this->assertSame(1, $form->length);
        $this->assertSame(1, $page->query('.//input[@name="q"]['.self::hasClass('g-input').']', $form->item(0))->length);
        $this->assertSame(1, $page->query('.//button[@type="submit"]['.self::hasClass('g-btn--primary').']', $form->item(0))->length);
        $this->assertSame(1, $page->query('//main//*['.self::hasClass('g-card').']//p[@id="personas-resultados"][@aria-live="polite"]')->length);
        $this->assertSame(PeopleController::PER_PAGE, $page->query('//main//*['.self::hasClass('g-card').']//ul/li[starts-with(@id, "p-")]')->length);
        $this->assertSame(1, $page->query('//main//*['.self::hasClass('g-card').']//nav[@aria-label="Páginas de personas"]//a[@rel="next"]['.self::hasClass('g-btn').']')->length);
    }

    public function test_an_account_without_a_directory_gets_the_notice_on_glass(): void
    {
        $guest = User::factory()->microsoft($this->segurex)->create(['is_guest' => true]);

        $page = $this->xpathOf($this->actingAs($guest)->get(route('people.index'))->assertOk());

        $this->assertGlassPage($page, '/personas without a directory');
        $this->assertSame(1, $page->query('//main//*['.self::hasClass('g-card').'][contains(., "no está disponible para esta cuenta")]')->length);
    }
}
