<?php

namespace Tests\Feature\Assistant;

use App\Assistant\Answer;
use App\Assistant\Assistant;
use App\Assistant\AssistantLog;
use App\Assistant\AssistantTools;
use App\Assistant\Conversation;
use App\Assistant\Model\AssistantModel;
use App\Assistant\Tools\BorradorDeAnuncio;
use App\Audit\AuditPresenter;
use App\Audit\SecurityLog;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The question loop with a fake model: tools run for the signed-in
 * account, a refusal goes once to the fallback model, failures and
 * runaway tool loops end with a message, and every question leaves one
 * audit line without its text.
 */
class AssistantTest extends TestCase
{
    use RefreshDatabase;

    private FakeAssistantModel $model;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00', 'America/Bogota'));
        config(['assistant.model' => 'claude-opus-5-5', 'assistant.fallback_model' => 'claude-opus-4-8', 'assistant.effort' => 'low']);

        $this->model = new FakeAssistantModel;
        $this->app->instance(AssistantModel::class, $this->model);

        $company = Company::factory()->create(['name' => 'Segurex Latam S.A.S.', 'short_name' => 'Segurex']);
        $this->user = User::factory()->microsoft($company)->create(['name' => 'Jhon Castro']);
    }

    private function conversation(?User $user = null): Conversation
    {
        return new Conversation($this->app['session.store'], $user ?? $this->user, (int) config('assistant.history_turns'));
    }

    private function ask(string $question = '¿Qué apps tengo?', ?User $user = null): Answer
    {
        return app(Assistant::class)->ask($user ?? $this->user, $this->conversation($user), $question);
    }

    private function auditLine(): Activity
    {
        return Activity::query()->where('event', AssistantLog::ASKED)->sole();
    }

    public function test_a_plain_answer_is_audited_without_the_text(): void
    {
        $this->model->answers('Hola, Jhon.');

        $answer = $this->ask('¿Cuál es mi secreto?');

        $this->assertSame('Hola, Jhon.', $answer->text);
        $request = $this->model->requests[0];
        $this->assertSame('claude-opus-5-5', $request->model);
        $this->assertSame('low', $request->effort);
        $this->assertSame([['role' => 'user', 'content' => '¿Cuál es mi secreto?']], $request->messages);
        $this->assertStringContainsString('Persona: Jhon Castro, cuenta de Segurex.', $request->system[1]);
        $this->assertStringContainsString('Miércoles 4 de noviembre de 2026, 09:00', $request->system[1]);

        $line = $this->auditLine();
        $this->assertSame(SecurityLog::LOG, $line->log_name);
        $this->assertTrue($this->user->is($line->causer));
        $this->assertSame([
            'model' => 'claude-opus-5-5', 'tools' => [], 'tool_calls' => 0, 'input_tokens' => 100, 'output_tokens' => 20,
            'fallback' => false, 'refused' => false, 'draft' => false, 'error' => null,
        ], $line->properties->all());
        $this->assertStringNotContainsString('secreto', json_encode($line->getAttributes()));
        $this->assertStringNotContainsString('Hola', json_encode($line->getAttributes()));
        $this->assertSame(
            'Preguntó al asistente · sin herramientas · claude-opus-5-5 · 100 tokens de entrada y 20 de salida',
            app(AuditPresenter::class)->activity($line)->what,
        );
    }

    public function test_tool_calls_run_for_the_account_and_go_back_to_the_model(): void
    {
        $this->model->callsTool('mis_apps')->answers('No tienes apps todavía.');

        $answer = $this->ask();

        $this->assertSame('No tienes apps todavía.', $answer->text);
        $this->assertCount(2, $this->model->requests);
        $second = $this->model->requests[1]->messages;
        $this->assertSame('assistant', $second[1]['role']);
        $this->assertSame('tool_use', $second[1]['turn']->stopReason);
        $this->assertSame([['id' => 'toolu_1', 'content' => '{"apps":[],"limite":100}', 'isError' => false]], $second[2]['toolResults']);

        $this->assertSame(['mis_apps'], $answer->tools);
        $this->assertSame(
            'Preguntó al asistente · usó mis_apps · claude-opus-5-5 · 200 tokens de entrada y 40 de salida',
            app(AuditPresenter::class)->activity($this->auditLine())->what,
        );
    }

    public function test_an_unknown_tool_is_an_error_for_the_model_not_a_failure(): void
    {
        $this->model->callsTool('publicar_anuncio', ['titulo' => 'x'])->answers('No puedo publicar.');

        $answer = $this->ask();

        $this->assertSame('No puedo publicar.', $answer->text);
        $this->assertSame([['id' => 'toolu_1', 'content' => AssistantTools::UNKNOWN, 'isError' => true]], $this->model->requests[1]->messages[2]['toolResults']);
        $this->assertSame(0, Announcement::query()->count());
    }

    public function test_a_refusal_goes_once_to_the_fallback_model_with_the_same_request(): void
    {
        $this->model->refuses()->answers('Respuesta del respaldo.', model: 'claude-opus-4-8');

        $answer = $this->ask();

        $this->assertSame('Respuesta del respaldo.', $answer->text);
        $this->assertTrue($answer->fallback);
        $this->assertFalse($answer->refused);
        $this->assertSame(['claude-opus-5-5', 'claude-opus-4-8'], array_map(fn ($request) => $request->model, $this->model->requests));
        $this->assertSame($this->model->requests[0]->messages, $this->model->requests[1]->messages);
        $this->assertSame(
            'Preguntó al asistente · sin herramientas · claude-opus-4-8 · 150 tokens de entrada y 20 de salida · respondió el modelo de respaldo',
            app(AuditPresenter::class)->activity($this->auditLine())->what,
        );
    }

    public function test_the_fallback_model_goes_on_for_the_rest_of_the_question(): void
    {
        $this->model->callsTool('mis_apps')->refuses()->callsTool('mis_tareas', model: 'claude-opus-4-8')->answers('Listo.', model: 'claude-opus-4-8');

        $this->assertSame('Listo.', $this->ask()->text);
        $this->assertSame(['claude-opus-5-5', 'claude-opus-5-5', 'claude-opus-4-8', 'claude-opus-4-8'], array_map(fn ($request) => $request->model, $this->model->requests));
    }

    public function test_a_second_refusal_is_the_answer(): void
    {
        $this->model->refuses()->refuses('claude-opus-4-8');

        $answer = $this->ask();

        $this->assertSame(Assistant::REFUSED, $answer->text);
        $this->assertTrue($answer->refused);
        $this->assertStringEndsWith('· respondió el modelo de respaldo · el modelo no quiso responder', app(AuditPresenter::class)->activity($this->auditLine())->what);
    }

    public function test_without_a_fallback_model_a_refusal_is_the_answer_at_once(): void
    {
        config(['assistant.fallback_model' => '']);
        $this->model->refuses();

        $this->assertSame(Assistant::REFUSED, $this->ask()->text);
        $this->assertCount(1, $this->model->requests);
    }

    public function test_a_model_that_cannot_answer_gives_a_message_and_is_audited(): void
    {
        $this->model->fails('rate_limited');

        $answer = $this->ask();

        $this->assertSame(Assistant::UNAVAILABLE, $answer->text);
        $this->assertSame('rate_limited', $answer->error);
        $this->assertStringEndsWith('· no pudo responder (rate_limited)', app(AuditPresenter::class)->activity($this->auditLine())->what);
    }

    public function test_a_runaway_tool_loop_stops_after_the_configured_rounds(): void
    {
        config(['assistant.max_tool_rounds' => 2]);
        $this->model->callsTool('mis_apps')->callsTool('mis_apps', id: 'toolu_2')->callsTool('mis_apps', id: 'toolu_3');

        $answer = $this->ask();

        $this->assertSame(Assistant::TOO_MANY_ROUNDS, $answer->text);
        $this->assertCount(3, $this->model->requests);
        $this->assertSame(2, $answer->toolCalls);
    }

    public function test_past_turns_go_as_plain_text_and_only_the_newest_are_kept(): void
    {
        config(['assistant.history_turns' => 2]);
        $conversation = $this->conversation();
        $conversation->add('Uno', 'R1');
        $conversation->add('Dos', 'R2');
        $conversation->add('Tres', 'R3');

        $this->model->answers('R4');
        $this->ask('Cuatro');

        $this->assertSame([
            ['role' => 'user', 'content' => 'Dos'],
            ['role' => 'assistant', 'content' => 'R2'],
            ['role' => 'user', 'content' => 'Tres'],
            ['role' => 'assistant', 'content' => 'R3'],
            ['role' => 'user', 'content' => 'Cuatro'],
        ], $this->model->requests[0]->messages);
    }

    public function test_a_conversation_of_another_account_is_ignored(): void
    {
        $this->conversation()->add('Pregunta de Jhon', 'Respuesta');
        $other = User::factory()->microsoft($this->user->company)->create();

        $this->assertSame([], $this->conversation($other)->turns());
    }

    public function test_a_wrong_effort_falls_back_to_low(): void
    {
        config(['assistant.effort' => 'turbo']);
        $this->model->answers('ok')->answers('ok');

        $this->ask();
        config(['assistant.effort' => 'high']);
        $this->ask();

        $this->assertSame(['low', 'high'], array_map(fn ($request) => $request->effort, $this->model->requests));
    }

    public function test_an_editor_gets_the_draft_but_nothing_is_written(): void
    {
        $this->user->assignRole('announcements_editor');
        $editor = $this->user->fresh();
        $this->model->callsTool(BorradorDeAnuncio::NAME, ['titulo' => 'Pausa activa', 'texto' => 'A las 3 p. m.'])->answers('Te dejé el borrador.');

        $answer = $this->ask('Redacta un anuncio de pausa activa', $editor);

        $this->assertSame(['title' => 'Pausa activa', 'body' => 'A las 3 p. m.', 'requires_read' => false], $answer->draft?->toArray());
        $this->assertSame(0, Announcement::query()->count());
        $this->assertStringEndsWith('· propuso un borrador de anuncio', app(AuditPresenter::class)->activity($this->auditLine())->what);
        $this->assertContains(BorradorDeAnuncio::NAME, array_column($this->model->requests[0]->tools, 'name'));
    }

    public function test_questions_today_count_only_this_accounts_lines_of_today(): void
    {
        $this->model->answers('a')->answers('b')->answers('c');
        $this->ask();
        $this->ask();
        $other = User::factory()->microsoft($this->user->company)->create();
        $this->ask(user: $other);

        $this->assertSame(2, AssistantLog::questionsToday($this->user));

        $this->travelTo(CarbonImmutable::parse('2026-11-05 00:01', 'America/Bogota'));
        $this->assertSame(0, AssistantLog::questionsToday($this->user));
    }
}
