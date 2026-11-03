<?php

namespace Tests\Feature\Assistant;

use App\Assistant\Answer;
use App\Assistant\Assistant;
use App\Assistant\AssistantLog;
use App\Assistant\AssistantTools;
use App\Assistant\Conversation;
use App\Assistant\Model\AssistantModel;
use App\Assistant\SystemPrompt;
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
 * The question loop with a fake model: a static, cached prefix with the
 * context in the user turn; an append-only transcript; the model fixed per
 * conversation (Haiku for readers, Sonnet for editors); a refusal asked
 * again as a new request to the fallback; cut and runaway answers; and one
 * audit line per question with tokens and cost, never its text.
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
        config([
            'assistant.model' => 'claude-haiku-5-5',
            'assistant.editor_model' => 'claude-sonnet-5-5',
            'assistant.fallback_model' => 'claude-opus-5-5',
            'assistant.effort' => 'low',
        ]);

        $this->model = new FakeAssistantModel;
        $this->app->instance(AssistantModel::class, $this->model);

        $company = Company::factory()->create(['name' => 'Segurex Latam S.A.S.', 'short_name' => 'Segurex']);
        $this->user = User::factory()->microsoft($company)->create(['name' => 'Jhon Castro']);
    }

    private function conversation(?User $user = null): Conversation
    {
        return new Conversation($this->app['session.store'], $user ?? $this->user);
    }

    private function ask(string $question = '¿Qué apps tengo?', ?User $user = null): Answer
    {
        return app(Assistant::class)->ask($user ?? $this->user, $this->conversation($user), $question);
    }

    private function editor(): User
    {
        $this->user->assignRole('announcements_editor');

        return $this->user->fresh();
    }

    private function lastLine(): Activity
    {
        return Activity::query()->where('event', AssistantLog::ASKED)->latest('id')->firstOrFail();
    }

    private function audit(Activity $line): string
    {
        return app(AuditPresenter::class)->activity($line)->what;
    }

    public function test_the_prefix_is_static_and_the_context_goes_in_the_user_turn(): void
    {
        $this->model->answers('Hola, Jhon.');

        $this->assertSame('Hola, Jhon.', $this->ask('¿Cuál es mi secreto?')->text);

        $request = $this->model->requests[0];
        $this->assertSame('claude-haiku-5-5', $request->model);
        $this->assertSame('low', $request->effort);
        $this->assertSame(2000, $request->maxTokens);
        $this->assertSame(SystemPrompt::STABLE, $request->system);
        $this->assertStringNotContainsString('Jhon', $request->system);
        $this->assertStringNotContainsString('Segurex Latam', $request->system);

        $this->assertCount(1, $request->messages);
        [$context, $question] = $request->messages[0]['content'];
        $this->assertStringContainsString('Persona: Jhon Castro, cuenta de Segurex.', $context['text']);
        $this->assertStringContainsString('Miércoles 4 de noviembre de 2026, 09:00', $context['text']);
        $this->assertStringContainsString('No puede crear anuncios.', $context['text']);
        $this->assertSame(['type' => 'text', 'text' => '¿Cuál es mi secreto?'], $question);
    }

    public function test_the_transcript_is_append_only_with_the_thinking_kept(): void
    {
        $this->model->callsTool('mis_apps')->answers('No tienes apps.', signature: 'sig-a')->answers('De nada.', signature: 'sig-b');

        $this->ask('¿Qué apps tengo?');
        $this->travelTo(CarbonImmutable::parse('2026-11-04 09:05', 'America/Bogota'));
        $this->ask('Gracias');

        $first = $this->model->requests[1];
        $second = $this->model->requests[2];

        // Everything the second question sends before its own message is the first question, byte for byte.
        $kept = $this->conversation()->transcript();
        $this->assertCount(6, $kept);
        $this->assertSame(array_slice($kept, 0, 4), array_slice($second->messages, 0, 4));
        $this->assertSame([...$first->messages, $kept[3]], array_slice($second->messages, 0, 4));
        $this->assertSame(['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-toolu_1'], $kept[1]['content'][0]);
        $this->assertSame([['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => '{"apps":[],"limite":30}', 'is_error' => false]], $kept[2]['content']);
        $this->assertSame('sig-a', $kept[3]['content'][0]['signature']);
        // Same prefix: the cache holds.
        $this->assertSame($first->system, $second->system);
        $this->assertSame(json_encode($first->tools), json_encode($second->tools));
        $this->assertSame($first->model, $second->model);
        $this->assertStringContainsString('09:05', $second->messages[4]['content'][0]['text']);
    }

    public function test_readers_use_the_reader_model_and_editors_the_editor_model(): void
    {
        $this->model->answers('ok')->answers('ok', 'claude-sonnet-5-5');

        $this->ask();
        $other = User::factory()->microsoft($this->user->company)->create();
        $other->assignRole('announcements_editor');
        $editor = $other->fresh();
        app(Assistant::class)->ask($editor, $this->conversation($editor), 'Redacta algo');

        $this->assertSame(['claude-haiku-5-5', 'claude-sonnet-5-5'], array_map(fn ($request) => $request->model, $this->model->requests));
        $this->assertNotContains(BorradorDeAnuncio::NAME, array_column($this->model->requests[0]->tools, 'name'));
        $this->assertContains(BorradorDeAnuncio::NAME, array_column($this->model->requests[1]->tools, 'name'));
        $this->assertStringContainsString('Puede crear anuncios', $this->model->requests[1]->messages[0]['content'][0]['text']);
    }

    public function test_an_empty_editor_model_is_the_reader_model(): void
    {
        config(['assistant.editor_model' => '']);
        $this->model->answers('ok');

        $editor = $this->editor();
        app(Assistant::class)->ask($editor, $this->conversation($editor), 'Hola');

        $this->assertSame('claude-haiku-5-5', $this->model->requests[0]->model);
    }

    public function test_a_role_change_starts_a_new_conversation_instead_of_changing_its_prefix(): void
    {
        $this->model->answers('R1')->answers('R2', 'claude-sonnet-5-5');
        $this->ask('P1');
        $this->assertSame('anthropic:claude-haiku-5-5', $this->conversation()->model());

        $editor = $this->editor();
        $this->assertTrue(app(Assistant::class)->sync($editor, $this->conversation($editor)));
        $this->assertSame([], $this->conversation($editor)->transcript());

        app(Assistant::class)->ask($editor, $this->conversation($editor), 'P2');
        $this->assertSame('claude-sonnet-5-5', $this->model->requests[1]->model);
        $this->assertCount(1, $this->model->requests[1]->messages);
    }

    public function test_a_settings_change_starts_a_new_conversation_and_an_unchanged_one_does_not(): void
    {
        $this->model->answers('R1');
        $this->ask('P1');

        $this->assertFalse(app(Assistant::class)->sync($this->user, $this->conversation()));

        config(['assistant.effort' => 'medium']);
        $this->assertTrue(app(Assistant::class)->sync($this->user, $this->conversation()));
        $this->assertSame([], $this->conversation()->turns());
    }

    public function test_a_refusal_is_asked_again_as_a_new_request_to_the_fallback(): void
    {
        $this->model->answers('R1')->refuses()->answers('Respuesta del respaldo.', 'claude-opus-5-5');
        $this->ask('P1');

        $answer = $this->ask('P2');

        $this->assertSame('Respuesta del respaldo.', $answer->text);
        $this->assertTrue($answer->fallback);
        $this->assertFalse($answer->refused);
        $fallback = $this->model->requests[2];
        $this->assertSame('claude-opus-5-5', $fallback->model);
        // A fresh transcript: the conversation as plain text, no thinking blocks.
        $this->assertSame([
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'P1']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'R1']]],
        ], array_slice($fallback->messages, 0, 2));
        $this->assertStringNotContainsString('thinking', json_encode($fallback->messages));

        // The conversation keeps its model and only the answer's text.
        $this->assertSame('anthropic:claude-haiku-5-5', $this->conversation()->model());
        $kept = $this->conversation()->transcript();
        $this->assertSame(['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Respuesta del respaldo.']]], end($kept));
        $this->assertStringContainsString('· claude-opus-5-5 ·', $this->audit($this->lastLine()));
        $this->assertStringContainsString('respondió el modelo de respaldo', $this->audit($this->lastLine()));
    }

    public function test_a_refusal_of_the_fallback_too_is_the_answer(): void
    {
        $this->model->refuses()->refuses('claude-opus-5-5');

        $answer = $this->ask();

        $this->assertSame(Assistant::REFUSED, $answer->text);
        $this->assertTrue($answer->refused);
        $this->assertStringEndsWith('· respondió el modelo de respaldo · el modelo no quiso responder', $this->audit($this->lastLine()));
    }

    public function test_without_a_fallback_model_a_refusal_is_the_answer_at_once(): void
    {
        config(['assistant.fallback_model' => '']);
        $this->model->refuses();

        $this->assertSame(Assistant::REFUSED, $this->ask()->text);
        $this->assertCount(1, $this->model->requests);
    }

    public function test_a_model_that_cannot_answer_keeps_nothing(): void
    {
        $this->model->fails('rate_limited');

        $answer = $this->ask();

        $this->assertSame(Assistant::UNAVAILABLE, $answer->text);
        $this->assertSame('rate_limited', $answer->error);
        $this->assertSame([], $this->conversation()->transcript());
        $this->assertStringEndsWith('· no pudo responder (rate_limited)', $this->audit($this->lastLine()));
    }

    public function test_a_cut_answer_says_so_and_is_kept_as_returned(): void
    {
        $this->model->cutsOff('Tienes tres apps y');

        $answer = $this->ask();

        $this->assertSame("Tienes tres apps y\n\n".Assistant::TRUNCATED, $answer->text);
        $this->assertTrue($answer->truncated);
        $kept = $this->conversation()->transcript();
        $this->assertSame([['type' => 'text', 'text' => 'Tienes tres apps y']], end($kept)['content']);
        $this->assertStringContainsString('la respuesta se cortó', $this->audit($this->lastLine()));
    }

    public function test_a_tool_call_cut_off_is_never_kept(): void
    {
        $this->model->cutsOff('', inToolCall: true);

        $answer = $this->ask();

        $this->assertSame(Assistant::TRUNCATED, $answer->text);
        $kept = $this->conversation()->transcript();
        $this->assertSame(['role' => 'assistant', 'content' => [['type' => 'text', 'text' => Assistant::TRUNCATED]]], end($kept));
        $this->assertStringNotContainsString('toolu_cut', json_encode($kept));
    }

    public function test_a_runaway_tool_loop_stops_and_keeps_only_complete_rounds(): void
    {
        config(['assistant.max_tool_rounds' => 2]);
        $this->model->callsTool('mis_apps')->callsTool('mis_apps', id: 'toolu_2')->callsTool('mis_apps', id: 'toolu_3');

        $answer = $this->ask();

        $this->assertSame(Assistant::TOO_MANY_ROUNDS, $answer->text);
        $this->assertCount(3, $this->model->requests);
        $this->assertSame(2, $answer->toolCalls);
        $this->assertStringNotContainsString('toolu_3', json_encode($this->conversation()->transcript()));
    }

    public function test_an_unknown_tool_is_an_error_for_the_model_not_a_failure(): void
    {
        $this->model->callsTool('publicar_anuncio', ['titulo' => 'x'])->answers('No puedo publicar.');

        $this->assertSame('No puedo publicar.', $this->ask()->text);
        $this->assertSame([['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => AssistantTools::UNKNOWN, 'is_error' => true]], $this->model->requests[1]->messages[2]['content']);
        $this->assertSame(0, Announcement::query()->count());
    }

    public function test_every_question_is_audited_with_tokens_and_cost_but_never_its_text(): void
    {
        // Haiku 5.5: 0.10 input, 0.50 output, 0.125 cache write, 0.01 cache read per MTok.
        $this->model->withUsage(input: 1000, output: 2000, cacheWrite: 4000, cacheRead: 50000)->callsTool('mis_apps')->answers('Hola, Jhon.');

        $this->ask('¿Cuál es mi secreto?');

        $line = $this->lastLine();
        $this->assertSame(SecurityLog::LOG, $line->log_name);
        $this->assertTrue($this->user->is($line->causer));
        $this->assertSame([
            'provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'feature' => 'chat', 'tools' => ['mis_apps'], 'tool_calls' => 1, 'model_calls' => 2,
            'input_tokens' => 2000, 'output_tokens' => 4000, 'cache_creation_input_tokens' => 8000, 'cache_read_input_tokens' => 100000,
            'cost_usd' => 0.0042, 'fallback' => false, 'failover' => false, 'refused' => false, 'truncated' => false, 'draft' => false, 'error' => null,
        ], $line->properties->all());
        $this->assertStringNotContainsString('secreto', json_encode($line->getAttributes()));
        $this->assertStringNotContainsString('Hola', json_encode($line->getAttributes()));
        $this->assertSame(
            'Preguntó al asistente · usó mis_apps · claude-haiku-5-5 · 2.000 tokens de entrada, 100.000 leídos de caché, 8.000 escritos en caché y 4.000 de salida · US$ 0,0042',
            $this->audit($line),
        );
    }

    public function test_a_wrong_effort_is_low(): void
    {
        config(['assistant.effort' => 'turbo']);
        $this->model->answers('ok');

        $this->ask();

        $this->assertSame('low', $this->model->requests[0]->effort);
    }

    public function test_an_editor_gets_the_draft_but_nothing_is_written(): void
    {
        $editor = $this->editor();
        $this->model->callsTool(BorradorDeAnuncio::NAME, ['titulo' => 'Pausa activa', 'texto' => 'A las 3 p. m.', 'lectura_obligatoria' => false], model: 'claude-sonnet-5-5')
            ->answers('Te dejé el borrador.', 'claude-sonnet-5-5');

        $answer = app(Assistant::class)->ask($editor, $this->conversation($editor), 'Redacta un anuncio de pausa activa');

        $this->assertSame(['title' => 'Pausa activa', 'body' => 'A las 3 p. m.', 'requires_read' => false], $answer->draft?->toArray());
        $this->assertSame(0, Announcement::query()->count());
        $this->assertSame('draft', $this->lastLine()->properties['feature']);
        $this->assertStringStartsWith('Preguntó al asistente (borrador)', $this->audit($this->lastLine()));
        $draftTool = collect($this->model->requests[0]->tools)->firstWhere('name', BorradorDeAnuncio::NAME);
        $this->assertTrue($draftTool['strict']);
        $this->assertFalse($draftTool['inputSchema']['additionalProperties']);
        $this->assertSame(['titulo', 'texto', 'lectura_obligatoria'], $draftTool['inputSchema']['required']);
    }

    public function test_a_full_conversation_is_never_cut(): void
    {
        config(['assistant.conversation_turns' => 2]);
        $this->model->answers('R1')->answers('R2');

        $this->ask('P1');
        $this->assertFalse($this->conversation()->isFull(2, 1_000_000));
        $this->ask('P2');

        $this->assertTrue($this->conversation()->isFull(2, 1_000_000));
        $this->assertCount(4, $this->conversation()->transcript());
        $this->assertTrue($this->conversation()->isFull(10, 100), 'Over the size, it is full too.');
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

    public function test_a_conversation_of_another_account_is_ignored(): void
    {
        $this->model->answers('Respuesta');
        $this->ask('Pregunta de Jhon');
        $other = User::factory()->microsoft($this->user->company)->create();

        $this->assertSame([], $this->conversation($other)->turns());
        $this->assertFalse($this->conversation($other)->exists());
    }
}
