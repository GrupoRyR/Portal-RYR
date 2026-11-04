<?php

namespace Tests\Feature\Layouts;

use App\Assistant\AssistantSwitch;
use App\Models\Company;
use App\Models\Person;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * <x-portal-header> (odd/tasks/liquid-glass.md G3): one glass top bar for
 * the home screen and the secondary pages, with the company logo on its
 * plate, the company switcher, the search, a slot for the bell (G4) and
 * the user menu with «Tus empresas» and the theme choice.
 */
class PortalHeaderTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::disk('public')->put('brand/segurex/logo-dark.png', 'png');

        $this->segurex = Company::factory()->create([
            'name' => 'Segurex Latam S.A.S.',
            'short_name' => 'Segurex',
            'slug' => 'segurex',
            'logo_dark_path' => 'brand/segurex/logo-dark.png',
        ]);
        $this->user = User::factory()->microsoft($this->segurex)->create(['name' => 'Ana María Pérez', 'email' => 'ana@segurex.com']);
    }

    public function test_the_home_screen_opens_with_the_shared_glass_header(): void
    {
        $page = $this->page('/');

        $this->assertSame(1, $page->query('//header[@data-portal-header][contains(@class, "g-topbar")]')->length);
        $this->assertSame(1, $page->query('//body[contains(@class, "g-canvas")]')->length);
        $this->assertSame(0, $page->query('//*[contains(@class, "brand-band")]')->length);
        // The white logo on its gray plate, linking back to the home screen.
        $this->assertSame(1, $page->query('//header[@data-portal-header]//a[@href="'.route('home').'"]//img[@alt="Segurex Latam S.A.S."]')->length);
        $this->assertSame(1, $page->query('//header[@data-portal-header]//details[@id="cuenta"][@data-account][@data-menu]')->length);
    }

    public function test_a_secondary_page_opens_with_the_same_header(): void
    {
        $page = $this->page(route('announcements.index', absolute: false));

        $this->assertSame(1, $page->query('//header[@data-portal-header][contains(@class, "g-topbar")]')->length);
        $this->assertSame(1, $page->query('//body[contains(@class, "g-canvas")]')->length);
        $this->assertSame(0, $page->query('//*[contains(@class, "brand-band")]')->length);
        // The account menu of the home screen, sign out included.
        $this->assertSame(1, $page->query('//details[@data-account]//form[@method="POST"][@action="'.route('logout').'"]')->length);
        $this->assertSame(1, $page->query('//details[@data-account]//a[@href="'.route('account.show').'"]')->length);
        $this->assertStringContainsString('Ana María Pérez', $page->query('//details[@data-account]')->item(0)->textContent);
    }

    public function test_the_search_of_a_secondary_page_is_a_search_of_the_home_screen(): void
    {
        $page = $this->page(route('announcements.index', absolute: false));

        $form = $page->query('//header[@data-portal-header]//form[@role="search"]');
        $this->assertSame(1, $form->length);
        $this->assertSame(route('home'), $form->item(0)->getAttribute('action'));
        $this->assertSame('GET', strtoupper($form->item(0)->getAttribute('method')));
        $this->assertSame(1, $page->query('//header[@data-portal-header]//form[@role="search"]//input[@name="q"]')->length);
        // «Ctrl K» needs the home screen's index and script.
        $this->assertSame(0, $page->query('//*[@data-search-open]')->length);
    }

    public function test_the_user_menu_offers_the_theme_choice(): void
    {
        foreach (['/', route('announcements.index', absolute: false)] as $path) {
            $page = $this->page($path);

            // Shown by resources/js/header.js: it does nothing without it.
            $group = $page->query('//details[@data-account]//*[@data-theme-switch][@hidden]//*[@role="group"]');
            $this->assertSame(1, $group->length, $path);

            $buttons = $page->query('//*[@data-theme-switch]//button[@type="button"][@data-theme-choice][@aria-pressed]');
            $this->assertSame(['light', 'dark', 'system'], array_map(fn ($button) => $button->getAttribute('data-theme-choice'), iterator_to_array($buttons)));
            $this->assertSame(['Claro', 'Oscuro', 'Sistema'], array_map(fn ($button) => trim($button->textContent), iterator_to_array($buttons)));
        }
    }

    public function test_every_page_with_the_header_loads_its_script(): void
    {
        $this->actingAs($this->user)->get(route('announcements.index'))->assertOk()->assertSee('/build/assets/header-', false);
        $this->actingAs($this->user)->get('/')->assertOk()->assertSee('/build/assets/header-', false);
    }

    public function test_the_bell_comes_before_the_user_menu(): void
    {
        $this->actingAs($this->user);

        $html = (string) $this->blade('<x-portal-header />');
        $this->assertMatchesRegularExpression('~<div [^>]*data-header-bell[^>]*>\s*<details [^>]*data-bell[^>]*>.*id="cuenta"~s', $html);

        // Nobody signed in: nothing to tell.
        auth()->logout();
        $this->assertStringNotContainsString('data-header-bell', (string) $this->blade('<x-portal-header />'));
    }

    public function test_the_assistant_link_follows_the_assistant_switch(): void
    {
        config(['assistant.enabled' => true]);
        DB::table('portal_settings')->insert(['key' => AssistantSwitch::SETTING, 'value' => 'true']);
        $link = '//details[@data-account]//a[@href="'.route('assistant.show').'"]';

        $this->assertSame(1, $this->page(route('announcements.index', absolute: false))->query($link)->length);
        $this->assertSame(1, $this->page('/')->query($link)->length);

        DB::table('portal_settings')->where('key', AssistantSwitch::SETTING)->update(['value' => 'false']);

        $this->assertSame(0, $this->page(route('announcements.index', absolute: false))->query($link)->length);
        $this->assertSame(0, $this->page('/')->query($link)->length);
    }

    public function test_the_user_menu_lists_the_linked_companies(): void
    {
        $derivados = User::factory()->microsoft(Company::factory()->create(['name' => 'Soluciones y Derivados', 'short_name' => 'Derivados']))->create();
        $person = Person::query()->create();
        $this->user->forceFill(['person_id' => $person->id])->save();
        $derivados->forceFill(['person_id' => $person->id])->save();

        foreach (['/', route('announcements.index', absolute: false)] as $path) {
            $companies = $this->page($path)->query('//details[@data-account]//*[@data-account-companies]');

            $this->assertSame(1, $companies->length, $path);
            $this->assertStringContainsString('Tus empresas', $companies->item(0)->textContent);
            $this->assertStringContainsString('Segurex', $companies->item(0)->textContent);
            $this->assertSame(1, (new DOMXPath($companies->item(0)->ownerDocument))->query('.//form[@action="'.route('account.switch', $derivados).'"]', $companies->item(0))->length);
        }
    }

    public function test_the_switcher_and_the_company_list_are_hidden_without_linked_accounts(): void
    {
        foreach (['/', route('announcements.index', absolute: false)] as $path) {
            $page = $this->page($path);

            $this->assertSame(0, $page->query('//*[@data-account-switcher]')->length, $path);
            $this->assertSame(0, $page->query('//*[@data-account-companies]')->length, $path);
        }
    }

    public function test_emergency_accounts_get_no_switcher_and_no_linked_accounts_link(): void
    {
        $emergency = User::factory()->emergency()->create();
        $page = $this->page(route('announcements.index', absolute: false), $emergency);

        $this->assertSame(1, $page->query('//header[@data-portal-header]')->length);
        $this->assertSame(0, $page->query('//*[@data-account-switcher]')->length);
        $this->assertSame(0, $page->query('//*[@data-account-companies]')->length);
        $this->assertSame(0, $page->query('//details[@data-account]//a[@href="'.route('account.show').'"]')->length);
    }

    public function test_glass_pages_keep_one_browser_bar_color_per_scheme_for_the_theme_choice(): void
    {
        // resources/js/theme.js rewrites their media once a theme is chosen.
        foreach (['/', route('announcements.index', absolute: false)] as $path) {
            $html = $this->actingAs($this->user)->get($path)->assertOk()->getContent();

            $this->assertStringContainsString('<meta name="theme-color" content="#F5F5F2" media="(prefers-color-scheme: light)">', $html);
            $this->assertStringContainsString('<meta name="theme-color" content="#121315" media="(prefers-color-scheme: dark)">', $html);
        }
    }

    private function page(string $path, ?User $user = null): DOMXPath
    {
        $html = $this->actingAs(($user ?? $this->user)->fresh())->get($path)->assertOk()->getContent();

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);

        return new DOMXPath($dom);
    }
}
