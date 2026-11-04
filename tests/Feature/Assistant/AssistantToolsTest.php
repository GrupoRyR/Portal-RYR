<?php

namespace Tests\Feature\Assistant;

use App\Assistant\AssistantTools;
use App\Assistant\Tools\BorradorDeAnuncio;
use App\Mcp\Tools\Anuncio;
use App\Models\Announcement;
use App\Models\App;
use App\Models\Company;
use App\Models\Integration;
use App\Models\ItService;
use App\Models\Person;
use App\Models\User;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The assistant's tools read only with the signed-in (active) account,
 * through the Claude connector's own tools and visibility code: a linked
 * account of another company is never read. `borrador_de_anuncio` is only
 * for announcement editors and never writes.
 */
class AssistantToolsTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private Company $derivados;

    private User $active;

    private User $linked;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00', 'America/Bogota'));

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam S.A.S.', 'short_name' => 'Segurex']);
        $this->derivados = Company::factory()->create(['name' => 'Soluciones y Derivados', 'short_name' => 'Derivados']);

        $this->active = User::factory()->microsoft($this->segurex)->create(['name' => 'Jhon Castro', 'email' => 'jhon.castro@segurex.com']);
        $this->linked = User::factory()->microsoft($this->derivados)->create(['name' => 'Jhon Castro', 'email' => 'jhon@derivados.com']);

        $person = Person::query()->create();
        $this->active->forceFill(['person_id' => $person->id])->save();
        $this->linked->forceFill(['person_id' => $person->id])->save();
    }

    private function tools(): AssistantTools
    {
        return app(AssistantTools::class);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function data(string $tool, array $input = [], ?User $user = null): array
    {
        $outcome = $this->tools()->run($user ?? $this->active, $tool, $input);
        $this->assertFalse($outcome->isError, $outcome->content);

        return json_decode($outcome->content, true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_everyone_gets_the_read_tools_and_only_editors_the_draft_tool(): void
    {
        $read = ['anuncios_recientes', 'anuncios_pendientes', 'anuncio', 'proximas_fechas', 'cumpleanos_y_aniversarios',
            'buscar_personas', 'mis_apps', 'mis_tareas', 'mis_aprobaciones', 'estado_de_ti'];

        $this->assertSame($read, array_column($this->tools()->definitions($this->active), 'name'));

        $this->active->assignRole('announcements_editor');
        $this->assertSame([...$read, BorradorDeAnuncio::NAME], array_column($this->tools()->definitions($this->active->fresh()), 'name'));
    }

    public function test_definitions_reuse_the_connector_schema(): void
    {
        $definitions = collect($this->tools()->definitions($this->active))->keyBy('name');

        $this->assertSame(app(Anuncio::class)->description(), $definitions['anuncio']['description']);
        $this->assertSame(['id'], $definitions['anuncio']['inputSchema']['required']);
        $this->assertSame('object', $definitions['mis_tareas']['inputSchema']['type']);
    }

    public function test_announcements_are_read_for_the_active_company_only(): void
    {
        Announcement::factory()->forCompanies($this->derivados)->create(['title' => 'Solo Derivados']);
        $mine = Announcement::factory()->forCompanies($this->segurex)->create(['title' => 'Solo Segurex', 'body' => 'Hola equipo.']);
        $pinned = Announcement::factory()->pinned()->create(['title' => 'Para todos', 'starts_at' => now()->subWeek()]);

        $listed = $this->data('anuncios_recientes')['anuncios'];

        $this->assertSame([$pinned->id, $mine->id], array_column($listed, 'id'));
        $this->assertSame(['Segurex'], $listed[1]['empresas']);
        $this->assertSame('Hola equipo.', $listed[1]['texto']);
        $this->assertTrue($listed[0]['fijado']);
    }

    public function test_an_announcement_of_the_linked_company_is_not_found(): void
    {
        $other = Announcement::factory()->forCompanies($this->derivados)->create();

        $outcome = $this->tools()->run($this->active, 'anuncio', ['id' => $other->id]);

        $this->assertTrue($outcome->isError);
        $this->assertSame(Anuncio::NOT_FOUND, $outcome->content);
    }

    public function test_pending_announcements_follow_the_read_rule_of_the_person(): void
    {
        $read = Announcement::factory()->mandatory()->create(['title' => 'Leído en Derivados']);
        $read->markReadBy($this->linked);
        $pending = Announcement::factory()->mandatory()->create(['title' => 'Pendiente']);

        $this->assertSame([$pending->id], array_column($this->data('anuncios_pendientes')['anuncios'], 'id'));
    }

    public function test_tasks_are_only_the_active_accounts(): void
    {
        $app = App::factory()->create(['slug' => 'tareas', 'url' => 'https://tareas.segurex.com.co']);
        $integration = Integration::factory()->create([
            'abilities' => [Integration::WORK_WRITE],
            'app_id' => $app->id,
            'app_base_url' => 'https://tareas.segurex.com.co',
        ]);
        $item = fn (User $for, string $title) => WorkItem::factory()->for($integration)->create([
            'kind' => WorkItem::KIND_TASK,
            'title' => $title,
            'assignee_tenant_id' => strtolower((string) $for->tenant_id),
            'assignee_oid' => strtolower((string) $for->azure_oid),
        ]);
        $item($this->active, 'Mía en Segurex');
        $item($this->linked, 'De Derivados');

        $tasks = $this->data('mis_tareas')['tareas'];

        $this->assertSame(['Mía en Segurex'], array_column($tasks, 'titulo'));
        $this->assertSame('Segurex', $tasks[0]['empresa']);
    }

    public function test_bad_input_comes_back_as_a_spanish_error_for_the_model(): void
    {
        $outcome = $this->tools()->run($this->active, 'buscar_personas', ['texto' => 'a']);

        $this->assertTrue($outcome->isError);
        $this->assertSame('El campo texto debe ser al menos 2.', $outcome->content);
    }

    public function test_it_status_is_the_active_companys(): void
    {
        ItService::factory()->for($this->segurex)->down('Sin correo desde las 8')->create(['name' => 'Correo']);
        ItService::factory()->for($this->derivados)->down()->create(['name' => 'VPN']);

        $board = $this->data('estado_de_ti')['estado'];

        $this->assertCount(1, $board);
        $this->assertSame('Segurex', $board[0]['empresa']);
        $this->assertFalse($board[0]['todo_bien']);
        $this->assertSame([['nombre' => 'Correo', 'estado' => 'Fuera de servicio', 'mensaje' => 'Sin correo desde las 8', 'hasta' => null, 'hasta_texto' => null]], $board[0]['servicios']);
    }

    public function test_the_draft_tool_proposes_and_writes_nothing(): void
    {
        $this->active->assignRole('announcements_editor');

        $outcome = $this->tools()->run($this->active->fresh(), BorradorDeAnuncio::NAME, [
            'titulo' => '  Pausa activa  ',
            'texto' => 'Hoy a las 3 p. m. en el patio.',
            'lectura_obligatoria' => true,
        ]);

        $this->assertFalse($outcome->isError);
        $this->assertSame(['estado' => BorradorDeAnuncio::PROPOSED], json_decode($outcome->content, true));
        $this->assertSame(['title' => 'Pausa activa', 'body' => 'Hoy a las 3 p. m. en el patio.', 'requires_read' => true], $outcome->draft?->toArray());
        $this->assertSame(0, Announcement::query()->count());
    }

    public function test_the_draft_tool_follows_the_editor_rules(): void
    {
        $this->active->assignRole('announcements_editor');
        $user = $this->active->fresh();

        $this->assertSame('El título va en una sola línea.', $this->tools()->run($user, BorradorDeAnuncio::NAME, ['titulo' => "Uno\nDos", 'texto' => 'x'])->content);
        $this->assertSame('El texto admite como máximo '.Announcement::BODY_MAX.' caracteres.', $this->tools()->run($user, BorradorDeAnuncio::NAME, ['titulo' => 'T', 'texto' => str_repeat('a', Announcement::BODY_MAX + 1)])->content);
    }

    public function test_a_tool_the_account_does_not_get_is_refused(): void
    {
        $outcome = $this->tools()->run($this->active, BorradorDeAnuncio::NAME, ['titulo' => 'Hola', 'texto' => 'Mundo']);

        $this->assertTrue($outcome->isError);
        $this->assertSame(AssistantTools::UNKNOWN, $outcome->content);
        $this->assertNull($outcome->draft);

        $this->assertSame(AssistantTools::UNKNOWN, $this->tools()->run($this->active, 'publicar_anuncio', [])->content);
    }
}
