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
 * The old floating «Asistente» button (<x-assistant-fab>, odd/tasks/liquid-glass.md
 * G7) was replaced by the agent's button (odd/tasks/agente-conexion.md): no
 * page draws it any more. /asistente stays, for the announcement drafts, with
 * its forms and its link in the user menu.
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

    public function test_the_old_button_no_longer_floats_anywhere(): void
    {
        // The agent's button replaced it (tests/Feature/Agent/AgentPanelTest.php);
        // the panel script and its endpoints stay in the code, unused by the pages.
        $pages = ['/', 'announcements.index', 'events.index', 'people.index', 'account.show'];

        foreach (array_map(fn (string $page) => $page === '/' ? $page : route($page, absolute: false), $pages) as $path) {
            $this->assertSame(0, $this->page($path)->query(self::FAB)->length, $path);
        }

        $html = $this->actingAs($this->user)->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString($this->fabModule(), $html);
        $this->assertSame([], $this->model->requests);
    }

    public function test_a_glass_page_that_opts_in_does_not_draw_it_either(): void
    {
        $this->actingAs($this->user);

        $this->assertStringNotContainsString('data-assistant-fab', (string) $this->blade('<x-layouts.app glass assistant><main>Página</main></x-layouts.app>'));
    }

    public function test_the_assistant_page_stays_for_the_announcement_drafts_and_links_from_the_menu(): void
    {
        $this->assertSame(0, $this->page(route('assistant.show', absolute: false))->query(self::FAB)->length);
        $this->assertSame(1, $this->page('/')->query('//a[@href="'.route('assistant.show').'"]')->length);
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
