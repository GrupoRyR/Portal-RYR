<?php

namespace Tests\Feature\Assistant;

use App\Assistant\Assistant;
use App\Assistant\AssistantLog;
use App\Assistant\AssistantSettings;
use App\Assistant\AssistantSwitch;
use App\Assistant\Conversation;
use App\Assistant\Model\AssistantModel;
use App\Assistant\Tools\BorradorDeAnuncio;
use App\Http\Controllers\Assistant\AssistantController;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\User;
use App\View\Assistant\AnswerText;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The floating chat panel talks to the assistant with fetch: `ask` and
 * `clear` answer JSON when asked for it, and GET /asistente/conversacion
 * reads the conversation. Same checks as the page, same messages, and the
 * same escaping (App\View\Assistant\AnswerText).
 */
class AssistantJsonTest extends TestCase
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
            'assistant.enabled' => true,
            'services.anthropic.key' => 'sk-ant-test',
            'assistant.daily_questions' => 40,
            'app.url' => 'https://portal.gruporyr.com',
        ]);
        DB::table('portal_settings')->insert(['key' => AssistantSwitch::SETTING, 'value' => 'true']);

        $this->model = new FakeAssistantModel;
        $this->app->instance(AssistantModel::class, $this->model);

        $company = Company::factory()->create(['name' => 'Segurex Latam S.A.S.', 'short_name' => 'Segurex']);
        $this->user = User::factory()->microsoft($company)->create(['name' => 'Jhon Castro']);
    }

    private function ask(string $question, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->user)->postJson(route('assistant.ask'), ['pregunta' => $question]);
    }

    public function test_a_question_is_answered_as_json_with_the_page_rendering(): void
    {
        $answer = "Tienes 2 tareas.\nMira https://portal.gruporyr.com/anuncios/5. O https://evil.example/x";
        $this->model->answers($answer);

        $this->ask('  ¿Qué tengo pendiente?  ')
            ->assertOk()
            ->assertExactJson([
                'turns' => [
                    ['role' => 'user', 'html' => '¿Qué tengo pendiente?'],
                    ['role' => 'assistant', 'html' => AnswerText::html($answer)->toHtml()],
                ],
                'draft' => null,
                'remaining' => 39,
                'error' => null,
                'full' => false,
                'budget_reached' => false,
            ])
            ->assertJsonPath('turns.1.html', fn (string $html) => str_contains($html, 'Tienes 2 tareas.<br>')
                && str_contains($html, '<a href="https://portal.gruporyr.com/anuncios/5" class="')
                && ! str_contains($html, 'href="https://evil.example/x"'));

        $this->assertCount(1, $this->model->requests);
        $this->assertSame(1, Activity::query()->where('event', AssistantLog::ASKED)->count());
    }

    public function test_markup_in_the_question_and_the_answer_is_escaped(): void
    {
        $this->model->answers('<script>alert(1)</script><b>hola</b>');

        $this->ask('<img src=x onerror=alert(1)> "hola" & adiós')
            ->assertOk()
            ->assertJsonPath('turns.0.html', '&lt;img src=x onerror=alert(1)&gt; &quot;hola&quot; &amp; adiós')
            ->assertJsonPath('turns.1.html', '&lt;script&gt;alert(1)&lt;/script&gt;&lt;b&gt;hola&lt;/b&gt;');
    }

    public function test_an_empty_or_long_question_is_a_422_with_the_page_message(): void
    {
        config(['assistant.question_max_length' => 10]);

        $this->ask('   ')->assertStatus(422)->assertExactJson(['error' => 'Escribe tu pregunta.']);
        $this->ask(str_repeat('a', 11))->assertStatus(422)->assertExactJson(['error' => 'Usa como máximo 10 caracteres.']);
        $this->assertSame([], $this->model->requests);
    }

    public function test_the_daily_cap_is_a_429_before_the_model(): void
    {
        config(['assistant.daily_questions' => 1]);
        $this->model->answers('Primera.');

        $this->ask('Uno')->assertOk()->assertJsonPath('remaining', 0);
        $this->ask('Dos')
            ->assertStatus(429)
            ->assertExactJson(['error' => 'Llegaste al límite de 1 preguntas de hoy. Mañana puedes seguir.']);

        $this->assertCount(1, $this->model->requests);
    }

    public function test_a_full_conversation_is_a_422_and_never_calls_the_model(): void
    {
        config(['assistant.conversation_turns' => 1]);
        $this->model->answers('R1');
        $this->ask('P1')->assertOk()->assertJsonPath('full', true);

        $this->ask('P2')
            ->assertStatus(422)
            ->assertExactJson(['error' => 'Esta conversación está llena. Empieza una «Nueva conversación» para seguir preguntando.']);
        $this->assertCount(1, $this->model->requests);
    }

    public function test_a_model_failure_is_a_503_and_adds_nothing(): void
    {
        $this->model->fails('timeout');

        $this->ask('¿Qué tengo?')->assertStatus(503)->assertExactJson(['error' => Assistant::UNAVAILABLE]);

        $this->assertSame([], (new Conversation(session()->driver(), $this->user))->turns());
    }

    public function test_without_the_api_key_nothing_is_sent(): void
    {
        config(['services.anthropic.key' => null]);

        $this->ask('Hola')
            ->assertStatus(503)
            ->assertExactJson(['error' => 'El asistente no está disponible en este momento. Vuelve a intentarlo más tarde.']);
        $this->assertSame([], $this->model->requests);

        $this->actingAs($this->user)->getJson(route('assistant.conversation'))
            ->assertOk()
            ->assertJsonPath('available', false);
    }

    public function test_every_json_route_is_a_404_while_it_is_off(): void
    {
        DB::table('portal_settings')->where('key', AssistantSwitch::SETTING)->update(['value' => 'false']);

        $this->actingAs($this->user)->getJson(route('assistant.conversation'))->assertNotFound();
        $this->ask('Hola')->assertNotFound();
        $this->actingAs($this->user)->postJson(route('assistant.clear'))->assertNotFound();
        $this->assertSame([], $this->model->requests);

        config(['assistant.enabled' => false]);
        DB::table('portal_settings')->where('key', AssistantSwitch::SETTING)->update(['value' => 'true']);

        $this->actingAs($this->user)->getJson(route('assistant.conversation'))->assertNotFound();
        $this->ask('Hola')->assertNotFound();
    }

    public function test_guests_get_a_401(): void
    {
        $this->getJson(route('assistant.conversation'))->assertUnauthorized();
        $this->postJson(route('assistant.ask'), ['pregunta' => 'Hola'])->assertUnauthorized();
    }

    public function test_the_conversation_endpoint_reads_the_greeting_and_the_turns(): void
    {
        $this->actingAs($this->user)->getJson(route('assistant.conversation'))
            ->assertOk()
            ->assertExactJson([
                'turns' => [],
                'draft' => null,
                'remaining' => 40,
                'error' => null,
                'available' => true,
                'greeting' => ['first_name' => 'Jhon', 'company' => 'Segurex', 'role' => null],
                'restarted' => false,
                'full' => false,
                'budget_reached' => false,
                'daily_questions' => 40,
                'max_length' => AssistantSettings::fromConfig()->questionMaxLength,
            ]);

        $this->model->answers('R1');
        $this->ask('P1')->assertOk();

        $this->actingAs($this->user)->getJson(route('assistant.conversation'))
            ->assertOk()
            ->assertJsonPath('turns', [
                ['role' => 'user', 'html' => 'P1'],
                ['role' => 'assistant', 'html' => 'R1'],
            ])
            ->assertJsonPath('remaining', 39);
    }

    public function test_a_role_change_is_a_409_and_the_conversation_starts_again(): void
    {
        $this->model->answers('R1');
        $this->ask('P1')->assertOk();
        $this->user->assignRole('announcements_editor');

        $this->ask('P2', $this->user->fresh())
            ->assertStatus(409)
            ->assertExactJson(['error' => 'Empezamos una conversación nueva porque cambió tu cuenta o la configuración del asistente.']);
        $this->assertCount(1, $this->model->requests);
        $this->actingAs($this->user->fresh())->getJson(route('assistant.conversation'))->assertJsonPath('turns', []);
    }

    public function test_at_the_monthly_budget_it_is_a_429_audited_before_the_model(): void
    {
        Notification::fake();
        config(['assistant.monthly_budget_usd' => 1.0]);
        activity('security')->event(AssistantLog::ASKED)->causedBy($this->user)
            ->withProperties(['cost_usd' => 1.0, 'feature' => 'chat'])->log(AssistantLog::ASKED);

        $this->ask('Hola')->assertStatus(429)->assertExactJson(['error' => AssistantController::BUDGET_REACHED]);

        $this->assertSame([], $this->model->requests);
        $line = Activity::query()->where('event', AssistantLog::BUDGET_BLOCKED)->sole();
        $this->assertTrue($this->user->is($line->causer));
        $this->assertEquals(['spent_usd' => 1.0, 'budget_usd' => 1.0], $line->properties->all());
        $this->actingAs($this->user)->getJson(route('assistant.conversation'))->assertJsonPath('budget_reached', true);
        $this->actingAs($this->user)->postJson(route('assistant.clear'))->assertOk()->assertJsonPath('budget_reached', true);
    }

    public function test_reading_the_conversation_leaves_the_restart_notice_to_the_page(): void
    {
        $this->model->answers('R1');
        $this->ask('P1')->assertOk();
        $this->user->assignRole('announcements_editor');
        $editor = $this->user->fresh();

        $this->actingAs($editor)->getJson(route('assistant.conversation'))
            ->assertOk()
            ->assertJsonPath('restarted', true)
            ->assertJsonPath('turns', [])
            ->assertJsonPath('draft', null);
        $this->actingAs($editor)->getJson(route('assistant.conversation'))->assertJsonPath('restarted', true);

        $this->actingAs($editor)->get(route('assistant.show'))
            ->assertOk()
            ->assertSeeText('Empezamos una conversación nueva porque cambió tu cuenta o la configuración del asistente.')
            ->assertDontSeeText('R1');
        $this->actingAs($editor)->getJson(route('assistant.conversation'))->assertJsonPath('restarted', false);
    }

    public function test_reading_the_conversation_has_its_own_bucket_and_a_spanish_429(): void
    {
        $this->assertContains('throttle:assistant-read', Route::getRoutes()->getByName('assistant.conversation')->gatherMiddleware());

        for ($i = 0; $i < 60; $i++) {
            $this->actingAs($this->user)->getJson(route('assistant.conversation'))->assertOk();
        }

        $this->actingAs($this->user)->getJson(route('assistant.conversation'))
            ->assertStatus(429)
            ->assertExactJson(['error' => 'Estás preguntando muy rápido. Espera un momento.']);

        // Another account has a bucket of its own.
        $other = User::factory()->microsoft($this->user->company)->create();
        $this->actingAs($other)->getJson(route('assistant.conversation'))->assertOk();
    }

    public function test_too_many_questions_a_minute_is_a_spanish_429_as_json_only(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->ask('')->assertStatus(422);
        }

        $this->ask('Hola')->assertStatus(429)->assertExactJson(['error' => 'Estás preguntando muy rápido. Espera un momento.']);
        $this->actingAs($this->user)->post(route('assistant.ask'), ['pregunta' => 'Hola'])
            ->assertStatus(429)
            ->assertDontSee('{"error"', false);
        $this->assertSame([], $this->model->requests);
    }

    public function test_the_conversation_endpoint_names_the_role(): void
    {
        $this->user->assignRole('announcements_editor');

        $this->actingAs($this->user->fresh())->getJson(route('assistant.conversation'))
            ->assertOk()
            ->assertJsonPath('greeting.role', 'Editor de anuncios');
    }

    public function test_another_account_on_the_same_session_never_reads_the_conversation(): void
    {
        $this->model->answers('Respuesta para Jhon');
        $this->ask('Pregunta de Jhon')->assertOk();

        $other = User::factory()->microsoft($this->user->company)->create();

        $this->actingAs($other)->getJson(route('assistant.conversation'))
            ->assertOk()
            ->assertJsonPath('turns', []);
    }

    public function test_clear_as_json_forgets_the_conversation(): void
    {
        $this->model->answers('R1');
        $this->ask('P1')->assertOk();

        $this->actingAs($this->user)->postJson(route('assistant.clear'))
            ->assertOk()
            ->assertExactJson([
                'turns' => [],
                'draft' => null,
                'remaining' => 39,
                'error' => null,
                'full' => false,
                'budget_reached' => false,
                'status' => 'Empezaste una conversación nueva.',
            ]);

        $this->assertNull(session(Conversation::KEY));
        $this->actingAs($this->user)->getJson(route('assistant.conversation'))->assertJsonPath('turns', []);
    }

    public function test_a_draft_comes_as_a_link_to_the_review_form_and_nothing_is_published(): void
    {
        $this->user->assignRole('announcements_editor');
        $editor = $this->user->fresh();
        $this->model->callsTool(BorradorDeAnuncio::NAME, ['titulo' => 'Pausa <b>activa</b>', 'texto' => 'Hoy a las 3 p. m. en el patio.'])
            ->answers('Te dejé el borrador para que lo revises.');

        $this->ask('Redacta un anuncio de pausa activa', $editor)
            ->assertOk()
            ->assertJsonPath('draft', [
                'title' => 'Pausa <b>activa</b>',
                'excerpt' => 'Hoy a las 3 p. m. en el patio.',
                'review_url' => '/asistente#borrador',
            ]);

        $this->actingAs($editor)->getJson(route('assistant.conversation'))
            ->assertJsonPath('draft.review_url', '/asistente#borrador');
        $this->actingAs($editor)->get(route('assistant.show'))->assertOk()->assertSee('id="borrador"', false);
        $this->assertSame(0, Announcement::query()->count());
    }

    public function test_the_html_form_still_redirects(): void
    {
        $this->model->answers('R1');

        $this->actingAs($this->user)->post(route('assistant.ask'), ['pregunta' => 'P1'])
            ->assertRedirect(route('assistant.show').'#respuesta');
        $this->actingAs($this->user)->post(route('assistant.clear'))
            ->assertRedirect(route('assistant.show'))
            ->assertSessionHas('status', 'Empezaste una conversación nueva.');
    }
}
