<?php

namespace Tests\Feature\Assistant;

use App\Assistant\AssistantSwitch;
use App\Assistant\Model\AssistantModel;
use App\Assistant\Tools\BorradorDeAnuncio;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

/**
 * The floating «Asistente» button (<x-assistant-fab>, odd/tasks/liquid-glass.md
 * G7): on every glass page of a signed-in account while the assistant is on,
 * a plain link to /asistente without JavaScript. resources/js/assistant-panel.js
 * (tests/js/assistant-panel.test.mjs) turns it into the chat panel. Never on
 * the sign-in, the error pages or /asistente itself, which keeps its forms.
 */
class AssistantFabTest extends TestCase
{
    use RefreshDatabase;

    private const FAB = '//a[@data-assistant-fab]';

    private FakeAssistantModel $model;

    private Company $segurex;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00:30', 'America/Bogota'));
        config(['assistant.enabled' => true, 'services.anthropic.key' => 'sk-ant-test']);
        DB::table('portal_settings')->insert(['key' => AssistantSwitch::SETTING, 'value' => 'true']);

        $this->model = new FakeAssistantModel;
        $this->app->instance(AssistantModel::class, $this->model);

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam S.A.S.', 'short_name' => 'Segurex', 'slug' => 'segurex']);
        $this->user = User::factory()->microsoft($this->segurex)->create(['name' => 'Jhon Castro']);
    }

    public function test_the_button_floats_on_the_home_screen_and_on_a_secondary_page(): void
    {
        foreach (['/', route('announcements.index', absolute: false), route('people.index', absolute: false)] as $path) {
            $fab = $this->page($path)->query(self::FAB);

            $this->assertSame(1, $fab->length, $path);
            $this->assertSame('Asistente', trim($fab->item(0)->textContent), $path);
        }
    }

    public function test_without_javascript_it_is_a_plain_link_to_the_assistant(): void
    {
        $fab = $this->page('/')->query(self::FAB)->item(0);

        $this->assertSame(route('assistant.show'), $fab->getAttribute('href'));
        $this->assertFalse($fab->hasAttribute('onclick'));
        $this->assertFalse($fab->hasAttribute('role'));
        // What the panel needs, read only once it is opened.
        $this->assertStringContainsString('/build/assets/assistant-panel-', $fab->getAttribute('data-module'));
        $this->assertSame(route('assistant.conversation', absolute: false), $fab->getAttribute('data-conversation-url'));
        $this->assertSame(route('assistant.ask', absolute: false), $fab->getAttribute('data-ask-url'));
        $this->assertSame(route('assistant.clear', absolute: false), $fab->getAttribute('data-clear-url'));
    }

    public function test_the_page_never_loads_the_panel_script_or_the_conversation_up_front(): void
    {
        $html = $this->actingAs($this->user)->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script type="module" src="'.$this->fabModule(), $html);
        $this->assertStringNotContainsString('rel="modulepreload" href="'.$this->fabModule(), $html);
        $this->assertSame([], $this->model->requests);
    }

    public function test_the_home_screen_raises_it_above_the_bottom_bar(): void
    {
        $raised = self::FAB.'[contains(concat(" ", @class, " "), " g-fab--raised ")]';

        $this->assertSame(1, $this->page('/')->query($raised)->length);
        $this->assertSame(0, $this->page(route('announcements.index', absolute: false))->query($raised)->length);
    }

    public function test_it_is_absent_on_the_assistant_page_itself(): void
    {
        $this->assertSame(0, $this->page(route('assistant.show', absolute: false))->query(self::FAB)->length);
    }

    public function test_it_is_absent_on_the_sign_in_page(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('data-assistant-fab', false);
    }

    public function test_it_is_absent_on_the_error_pages_of_a_signed_in_account(): void
    {
        $this->actingAs($this->user)->get('/admin')->assertForbidden()->assertDontSee('data-assistant-fab', false);
    }

    public function test_it_is_absent_while_the_admin_toggle_is_off(): void
    {
        DB::table('portal_settings')->where('key', AssistantSwitch::SETTING)->update(['value' => 'false']);

        $this->assertSame(0, $this->page('/')->query(self::FAB)->length);
        $this->assertSame(0, $this->page(route('announcements.index', absolute: false))->query(self::FAB)->length);
    }

    public function test_it_is_absent_while_the_deploy_flag_is_off(): void
    {
        config(['assistant.enabled' => false]);

        $this->assertSame(0, $this->page('/')->query(self::FAB)->length);
    }

    public function test_without_the_api_key_it_stays_so_the_panel_can_say_it_is_not_available(): void
    {
        config(['services.anthropic.key' => null]);

        $this->assertSame(1, $this->page('/')->query(self::FAB)->length);
    }

    public function test_the_assistant_page_is_glass_and_keeps_its_forms_and_anchors(): void
    {
        $this->user->assignRole('announcements_editor');
        $this->model->callsTool(BorradorDeAnuncio::NAME, ['titulo' => 'Pausa activa', 'texto' => 'Hoy a las 3 p. m. en el patio.'])
            ->answers('Te dejé el borrador para que lo revises.');
        $this->actingAs($this->user)->post(route('assistant.ask'), ['pregunta' => 'Redacta un anuncio de pausa activa'])->assertSessionHasNoErrors();

        $page = $this->page(route('assistant.show', absolute: false));

        $this->assertSame(1, $page->query('//body[contains(@class, "g-canvas")]')->length);
        $this->assertGreaterThanOrEqual(3, $page->query('//main[@id="contenido"]//*[contains(concat(" ", @class, " "), " g-card ")]')->length);

        $ask = '//form[@method="POST"][@action="'.route('assistant.ask').'"]';
        $this->assertSame(1, $page->query($ask.'//textarea[@id="pregunta"][@name="pregunta"]')->length);
        $this->assertSame(1, $page->query($ask.'//button[@type="submit"][normalize-space()="Enviar"]')->length);
        $this->assertSame(1, $page->query('//*[@id="respuesta"][@tabindex="-1"]')->length);
        $this->assertStringContainsString('Te dejé el borrador para que lo revises.', $page->query('//*[@id="respuesta"]')->item(0)->textContent);

        $publish = '//section[@aria-labelledby="borrador"]//form[@method="POST"][@action="'.route('assistant.draft.publish').'"]';
        $this->assertSame(1, $page->query('//h2[@id="borrador"][normalize-space()="Borrador de anuncio"]')->length);
        $this->assertSame(1, $page->query($publish.'//input[@id="borrador-titulo"][@name="title"][@value="Pausa activa"]')->length);
        $this->assertSame(1, $page->query($publish.'//textarea[@id="borrador-texto"][@name="body"]')->length);
        $this->assertSame(1, $page->query($publish.'//input[@type="checkbox"][@name="confirm"][@required]')->length);
        $this->assertSame(1, $page->query($publish.'//input[@type="checkbox"][@name="requires_read"]')->length);
        $this->assertSame(1, $page->query('//form[@method="POST"][@action="'.route('assistant.draft.discard').'"]//button[normalize-space()="Descartar borrador"]')->length);
        $this->assertSame(1, $page->query('//form[@method="POST"][@action="'.route('assistant.clear').'"]//button[normalize-space()="Nueva conversación"]')->length);
        $this->assertSame(1, $page->query('//a[@href="'.route('home').'"][normalize-space()="Volver al inicio"]')->length);
    }

    private function fabModule(): string
    {
        return (string) str(Vite::asset('resources/js/assistant-panel.js'))->before('?');
    }

    private function page(string $path): DOMXPath
    {
        $html = $this->actingAs($this->user->fresh())->get($path)->assertOk()->getContent();

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);

        return new DOMXPath($dom);
    }
}
