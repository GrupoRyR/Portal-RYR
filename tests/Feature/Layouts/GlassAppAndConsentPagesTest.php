<?php

namespace Tests\Feature\Layouts;

use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Mcp\InteractsWithMcp;
use Tests\TestCase;

/**
 * The app pages (apps/local, apps/maintenance) and the Claude consent pages
 * (mcp/authorize, mcp/refused) in Liquid Glass (odd/tasks/liquid-glass.md
 * G9): the .g-page column with its status as a g-pill and kit buttons.
 * The consent pages keep their own bar, with no account switcher and no
 * floating «Asistente», and never call the companies a group.
 */
class GlassAppAndConsentPagesTest extends TestCase
{
    use InspectsGlassPages, InteractsWithMcp, RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['name' => 'Segurex Latam S.A.S.', 'short_name' => 'Segurex']);
        $this->user = User::factory()->microsoft($this->company)->create(['name' => 'Jhon Castro', 'email' => 'jhon.castro@segurex.com']);
    }

    public function test_the_local_app_page_has_its_steps_in_a_glass_card(): void
    {
        $app = $this->openable(['type' => App::TYPE_LOCAL, 'url' => null, 'name' => 'Cámaras', 'local_instructions' => "Abre el menú Inicio\nBusca la app", 'download_url' => 'https://descargas.segurex.com.co/camaras.msi']);

        $page = $this->xpathOf($this->actingAs($this->user)->get(route('apps.open', $app))->assertOk());

        $this->assertGlassPage($page, 'apps/local');
        $this->assertSame(1, $page->query('//main//*['.self::hasClass('g-pill').'][contains(., "Instalada en tu equipo")]')->length);
        $this->assertSame(1, $page->query('//main//section['.self::hasClass('g-card').'][@aria-labelledby="como-abrirla"]//*['.self::hasClass('g-card__head').']/h2[@id="como-abrirla"]')->length);
        $this->assertSame(2, $page->query('//section[@aria-labelledby="como-abrirla"]//ol/li/*['.self::hasClass('g-page__step').'][@aria-hidden="true"]')->length);
        $this->assertSame(1, $page->query('//main//a[@href="https://descargas.segurex.com.co/camaras.msi"]['.self::hasClass('g-btn--primary').']')->length);
        // The way home is the banner's back link only.
        $this->assertSame(1, $page->query('//main//a[@href="'.route('home').'"]')->length);
        $this->assertSame(1, $page->query('//main//*['.self::hasClass('g-banner').']//a[@href="'.route('home').'"]')->length);
    }

    public function test_the_maintenance_page_shows_its_state_as_a_warning_pill(): void
    {
        $app = $this->openable(['status' => App::STATUS_MAINTENANCE, 'maintenance_until' => now()->addDay()]);

        $page = $this->xpathOf($this->actingAs($this->user)->get(route('apps.open', $app))->assertOk());

        $this->assertGlassPage($page, 'apps/maintenance');
        $this->assertSame(1, $page->query('//main//*['.self::hasClass('g-pill').']['.self::hasClass('warn').'][normalize-space(.)="En mantenimiento"]')->length);
        $this->assertSame(1, $page->query('//main//*['.self::hasClass('g-card').']//p['.self::hasClass('g-page__callout').'][contains(., "Vuelve a estar disponible")]')->length);
        $this->assertSame(1, $page->query('//main//a[@href="'.route('home').'"]['.self::hasClass('g-btn--primary').']')->length);
        $this->assertSame(1, $page->query('//body/a[@href="#contenido"][contains(., "Saltar al contenido")]')->length);
    }

    public function test_the_consent_screen_ends_the_registration_time_with_one_period(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 30)->setTime(13, 28));
        $this->enableMcp();

        $page = $this->xpathOf($this->actingAs($this->user)->get($this->authorizeUrl($this->registeredClientId()))->assertOk());

        $this->assertStringContainsString('Registrado el 30 de octubre de 2026 a las 1:28 p. m. ', $this->mainText($page).' ');
        $this->assertStringNotContainsString('m..', $this->mainText($page));
    }

    public function test_the_consent_screen_is_glass_with_its_forms_intact(): void
    {
        $this->enableMcp();
        $clientId = $this->registeredClientId();

        $page = $this->xpathOf($this->actingAs($this->user)->get($this->authorizeUrl($clientId))->assertOk());

        $this->assertGlassPage($page, 'mcp/authorize');
        // Its own bar: the logo, no account switcher, search or menus, no assistant.
        $this->assertSame(1, $page->query('//header['.self::hasClass('g-topbar').'][contains(., "Segurex Latam S.A.S.")]')->length);
        $this->assertSame(0, $page->query('//*[@data-portal-header or @data-account-switcher or @data-menu]')->length);
        $this->assertSame(0, $page->query('//*[@data-assistant-fab]')->length);

        // Approve and deny post to the same address; deny spoofs DELETE.
        $approve = $page->query('//main//form[@method="POST"][@action="'.route('passport.authorizations.approve').'"][not(.//input[@name="_method"])]');
        $this->assertSame(1, $approve->length);
        foreach (['_token', 'state', 'client_id', 'auth_token'] as $name) {
            $this->assertSame(1, $page->query('.//input[@type="hidden"][@name="'.$name.'"]', $approve->item(0))->length, $name);
        }
        $this->assertSame(1, $page->query('.//button[@type="submit"]['.self::hasClass('g-btn--primary').'][normalize-space(.)="Aprobar"]', $approve->item(0))->length);

        $deny = $page->query('//main//form[@method="POST"][@action="'.route('passport.authorizations.deny').'"][.//input[@name="_method"][@value="DELETE"]]');
        $this->assertSame(1, $deny->length);
        foreach (['_token', 'state', 'client_id', 'auth_token'] as $name) {
            $this->assertSame(1, $page->query('.//input[@type="hidden"][@name="'.$name.'"]', $deny->item(0))->length, $name);
        }
        $this->assertSame(1, $page->query('.//button[@type="submit"]['.self::hasClass('g-btn').'][normalize-space(.)="Rechazar"]', $deny->item(0))->length);

        $this->assertSame(1, $page->query('//section[@aria-labelledby="cuenta"]['.self::hasClass('g-card').']//*['.self::hasClass('g-pill').'][normalize-space(.)="Siempre incluida"]')->length);
        $this->assertSame(1, $page->query('//main//p[@role="note"]['.self::hasClass('g-card').']')->length);
    }

    public function test_the_refusal_is_glass_and_never_calls_the_companies_a_group(): void
    {
        $this->enableMcp();
        $guest = User::factory()->microsoft($this->company)->create(['is_guest' => true]);

        $page = $this->xpathOf($this->actingAs($guest)->get($this->authorizeUrl($this->registeredClientId()))->assertForbidden());

        $this->assertGlassPage($page, 'mcp/refused');
        $this->assertSame(0, $page->query('//*[@data-assistant-fab]')->length);
        $this->assertStringContainsString('Esta cuenta no puede conectar Claude.', $this->mainText($page));
        $this->assertStringNotContainsStringIgnoringCase('grupo', $this->mainText($page));
        $this->assertSame(1, $page->query('//main//a[@href="'.url('/').'"]['.self::hasClass('g-btn--primary').']')->length);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function openable(array $attributes): App
    {
        $app = App::factory()->forCompanies($this->company)->create($attributes);
        AppAccessRule::forCompany($app, $this->company);

        return $app;
    }
}
