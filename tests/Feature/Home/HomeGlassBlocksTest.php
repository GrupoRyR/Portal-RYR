<?php

namespace Tests\Feature\Home;

use App\Auth\MicrosoftLogin;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\CompanyKpi;
use App\Models\DirectoryEntry;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\View\Home\QuickLinks;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Home\Concerns\ReadsTheHome;
use Tests\TestCase;

/**
 * The home blocks in Liquid Glass (odd/tasks/liquid-glass.md G6), with the
 * block placements of the prototype: the greeting banner, then the glass
 * cards of the main column (Panel gerencial, Mis aplicaciones, Accesos
 * rápidos, Anuncios, Noticias, Catálogos) and the «Mi día» card beside it.
 */
class HomeGlassBlocksTest extends TestCase
{
    use ReadsTheHome;
    use RefreshDatabase;

    private const GROUP = 'bbbbbbbb-0000-4000-8000-000000000006';

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00', 'America/Bogota'));
        $this->company = Company::factory()->withManagementGroup(self::GROUP)->create(['name' => 'Segurex Latam']);
        $this->user = User::factory()->microsoft($this->company)->create(['name' => 'Ana Pérez']);
        $this->openableApp();
    }

    public function test_the_home_has_no_temporary_sheet(): void
    {
        $this->home()->assertDontSee('g-sheet', false);
    }

    public function test_the_greeting_is_a_glass_banner_first_in_the_main_column(): void
    {
        $xpath = $this->xpath($this->home());

        $column = $xpath->query('//main[@id="contenido"][contains(concat(" ", @class, " "), " g-home__col ")]');
        $this->assertSame(1, $column->length, 'Expected <main> to be the main column.');

        $first = $xpath->query('./*[1]', $column->item(0))->item(0);
        $this->assertStringContainsString('g-banner', $first->getAttribute('class'));
        $this->assertSame('inicio', $first->getAttribute('id'));
        $this->assertSame(1, $xpath->query('.//h1', $first)->length);
        $this->assertStringContainsString('Buenos días, Ana', $first->textContent);
    }

    public function test_every_block_is_a_glass_card_with_a_marked_heading(): void
    {
        $this->figures();
        $this->product('Cerradura digital S1');
        Announcement::factory()->create(['title' => 'Jornada de vacunación', 'starts_at' => now()->subHour()]);

        $xpath = $this->xpath($this->home(as: $this->manager()));

        foreach (['panel-gerencial', 'mis-aplicaciones', 'accesos', 'anuncios', 'catalogos', 'mi-dia'] as $id) {
            $block = $xpath->query("//*[@id=\"{$id}\"]");
            $this->assertSame(1, $block->length, "Expected #{$id}.");
            $this->assertStringContainsString('g-card', $block->item(0)->getAttribute('class'), "#{$id} is not a glass card.");

            $head = $xpath->query('./*[contains(concat(" ", @class, " "), " g-card__head ")]', $block->item(0));
            $this->assertSame(1, $head->length, "#{$id} has no card head.");
            $this->assertSame(1, $xpath->query('.//*[contains(concat(" ", @class, " "), " g-mark ")]', $head->item(0))->length, "#{$id} has no mark.");
            $this->assertSame(1, $xpath->query('.//h2', $head->item(0))->length, "#{$id} has no heading.");
        }
    }

    public function test_the_blocks_keep_the_placements_of_the_prototype(): void
    {
        $this->figures();
        $this->product('Cerradura digital S1');
        Announcement::factory()->create(['title' => 'Jornada de vacunación', 'starts_at' => now()->subHour()]);
        DirectoryEntry::factory()->forCompany($this->company)->create(['hire_date' => now('America/Bogota')->subYears(3)->toDateString()]);

        $xpath = $this->xpath($this->home(as: $this->manager()));
        $column = $xpath->query('//main[@id="contenido"]')->item(0);
        // «Mi día» is the main column's sibling: a top-level complementary landmark, not inside <main>.
        $rail = $xpath->query('//*[contains(concat(" ", @class, " "), " g-home ")][main[@id="contenido"]]/aside[@id="mi-dia"]');
        $this->assertSame(1, $rail->length, 'Expected «Mi día» beside the main column.');

        $this->assertSame(
            ['inicio', 'panel-gerencial', 'mis-aplicaciones', 'accesos', 'anuncios', 'catalogos'],
            $this->childIds($xpath, $column),
        );
        $this->assertSame(['celebraciones', 'fechas', 'personas', 'estado-ti'], $this->childIds($xpath, $rail->item(0)));
    }

    public function test_quick_links_are_accesos_rapidos_without_a_microsoft_365_title(): void
    {
        $this->company->update(['quick_links' => [['label' => 'SharePoint', 'url' => 'https://segurex.sharepoint.com']]]);

        $response = $this->home()->assertDontSeeText('Microsoft 365');
        $quick = $this->element($response, 'id', 'accesos');

        $this->assertStringContainsString('Accesos rápidos', $quick);
        foreach ([...array_values(QuickLinks::DEFAULTS), 'https://segurex.sharepoint.com'] as $url) {
            $this->assertStringContainsString('href="'.$url.'"', $quick);
        }

        $links = $this->xpath($response)->query('//*[@id="accesos"]//a[contains(concat(" ", @class, " "), " g-chip ")]');
        $this->assertSame(4, $links->length);
        foreach ($links as $link) {
            $this->assertSame('_blank', $link->getAttribute('target'));
            $this->assertSame('noopener', $link->getAttribute('rel'));
        }
    }

    public function test_my_day_is_a_glass_card_with_its_heading(): void
    {
        $xpath = $this->xpath($this->home());
        $rail = $xpath->query('//aside[@id="mi-dia"]')->item(0);

        $this->assertSame('mi-dia-titulo', $rail->getAttribute('aria-labelledby'));
        $this->assertSame('Mi día', trim($xpath->query('.//h2[@id="mi-dia-titulo"]', $rail)->item(0)->textContent));
        // Its blocks are sections of «Mi día»: h3 under its h2.
        $this->assertSame(0, $xpath->query('.//section//h2', $rail)->length);
        $this->assertSame(1, $xpath->query('.//section[@id="fechas"]//h3[normalize-space()="Próximas fechas"]', $rail)->length);
    }

    public function test_announcements_are_a_main_column_card_on_every_width(): void
    {
        Announcement::factory()->create(['title' => 'Jornada de vacunación', 'starts_at' => now()->subHour()]);

        $response = $this->home();
        $xpath = $this->xpath($response);
        $card = $xpath->query('//section[@data-announcements]');

        $this->assertSame(1, $card->length);
        $this->assertSame('anuncios', $card->item(0)->getAttribute('id'));
        $this->assertSame(0, $xpath->query('//aside//*[@data-announcements]')->length);
        $this->assertStringNotContainsString('hidden', $card->item(0)->getAttribute('class'));
        $this->assertStringContainsString('Jornada de vacunación', $card->item(0)->textContent);
        // The card shows on phones too: the phone banner would repeat it.
        $this->assertNoElement($response, 'data-announcement-banner');
    }

    public function test_mandatory_announcements_still_to_confirm_wait_in_my_day(): void
    {
        $mandatory = Announcement::factory()->mandatory()->create(['title' => 'Política de IA', 'starts_at' => now()->subDay()]);
        Announcement::factory()->create(['title' => 'Solo informativo', 'starts_at' => now()->subHour()]);

        $xpath = $this->xpath($this->home());
        $section = $xpath->query('//aside[@id="mi-dia"]//section[@id="por-confirmar"]');

        $this->assertSame(1, $section->length);
        $this->assertStringContainsString('Por confirmar', $section->item(0)->textContent);
        $this->assertStringContainsString('Confirma su lectura', $section->item(0)->textContent);
        $this->assertStringNotContainsString('Solo informativo', $section->item(0)->textContent);
        $this->assertSame(1, $xpath->query('.//a[@href="'.route('announcements.show', $mandatory->id).'"][normalize-space()="Política de IA"]', $section->item(0))->length);
    }

    public function test_nothing_to_confirm_leaves_the_block_out(): void
    {
        Announcement::factory()->create(['title' => 'Solo informativo', 'starts_at' => now()->subHour()]);

        $this->assertNoElement($this->home(), 'id', 'por-confirmar');
    }

    public function test_the_directory_block_searches_people_with_a_plain_form(): void
    {
        $xpath = $this->xpath($this->home());
        $form = $xpath->query('//aside[@id="mi-dia"]//section[@id="personas"]//form[@role="search"]');

        $this->assertSame(1, $form->length);
        $this->assertSame('get', strtolower($form->item(0)->getAttribute('method')));
        $this->assertSame(route('people.index'), $form->item(0)->getAttribute('action'));
        $input = $xpath->query('.//input[@name="q"][@type="search"]', $form->item(0));
        $this->assertSame(1, $input->length);
        $this->assertSame(1, $xpath->query('//label[@for="'.$input->item(0)->getAttribute('id').'"]')->length);
    }

    public function test_the_catalog_card_shows_a_visible_product(): void
    {
        $this->product('Cerradura digital S1');

        $xpath = $this->xpath($this->home());
        $tiles = $xpath->query('//*[@id="catalogos"]//li[@data-product]//*[contains(concat(" ", @class, " "), " g-tile ")]');

        $this->assertSame(1, $tiles->length);
        $this->assertStringContainsString('Cerradura digital S1', $tiles->item(0)->textContent);
    }

    public function test_apps_are_glass_tiles_inside_my_apps(): void
    {
        $xpath = $this->xpath($this->home());

        $card = $xpath->query('//section[@id="mis-aplicaciones"]');
        $this->assertSame(1, $card->length);
        $this->assertStringContainsString('Mis aplicaciones', $card->item(0)->textContent);
        $this->assertStringContainsString('Tienes 1 aplicación disponible en Segurex Latam.', $card->item(0)->textContent);
        $this->assertSame(1, $xpath->query('.//*[@id="favoritos"]', $card->item(0))->length);
        $this->assertSame(1, $xpath->query('.//*[@id="aplicaciones"]', $card->item(0))->length);
        $this->assertSame(1, $xpath->query('.//*[@id="resultados"]', $card->item(0))->length);

        $tiles = $xpath->query('.//li[@data-app]', $card->item(0));
        $this->assertSame(1, $tiles->length);
        $this->assertStringContainsString('g-tile', $tiles->item(0)->getAttribute('class'));
    }

    /**
     * The ids of the element's children, in page order.
     *
     * @return list<string>
     */
    private function childIds(\DOMXPath $xpath, \DOMNode $parent): array
    {
        $ids = [];

        foreach ($xpath->query('./*[@id]', $parent) as $child) {
            $ids[] = $child->getAttribute('id');
        }

        return $ids;
    }

    private function figures(): void
    {
        CompanyKpi::factory()->create([
            'company_id' => $this->company->id,
            'position' => 1,
            'as_of' => CarbonImmutable::parse('2026-11-04 23:59', 'America/Bogota'),
            'key' => 'ventas',
            'label' => 'Ventas del mes',
            'value' => 1250000000,
            'unit' => 'COP',
            'goal' => null,
        ]);
    }

    private function manager(): User
    {
        $user = User::factory()->microsoft($this->company)->create(['name' => 'Ana Pérez']);
        $user->assignRole(MicrosoftLogin::MANAGEMENT);
        $user->syncGroups([['id' => self::GROUP, 'display_name' => 'APP-PortalGerencia', 'security_group' => true]]);

        return $user;
    }

    private function product(string $name): Product
    {
        $category = ProductCategory::factory()->create(['company_id' => $this->company->id, 'name' => 'Línea smart', 'position' => 1]);

        return Product::factory()->published()->create([
            'company_id' => $this->company->id,
            'product_category_id' => $category->id,
            'name' => $name,
        ]);
    }
}
