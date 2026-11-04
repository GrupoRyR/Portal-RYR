<?php

namespace Tests\Feature\Assistant;

use App\Assistant\Assistant;
use App\Assistant\AssistantLog;
use App\Assistant\AssistantSwitch;
use App\Assistant\Conversation;
use App\Assistant\Model\AssistantModel;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * /asistente: a server-rendered page with plain form POSTs. A 404 while
 * the assistant is off, «no disponible» without the API key, the daily
 * cap before the model, and the conversation in the session only.
 */
class AssistantPageTest extends TestCase
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

    private function ask(string $question): TestResponse
    {
        return $this->actingAs($this->user)->post(route('assistant.ask'), ['pregunta' => $question]);
    }

    public function test_every_route_is_a_404_while_the_deploy_flag_is_off(): void
    {
        config(['assistant.enabled' => false]);

        $this->assertRoutesAreNotFound();
    }

    public function test_every_route_is_a_404_while_the_admin_toggle_is_off(): void
    {
        DB::table('portal_settings')->where('key', AssistantSwitch::SETTING)->update(['value' => 'false']);

        $this->assertRoutesAreNotFound();
    }

    private function assertRoutesAreNotFound(): void
    {
        $this->actingAs($this->user)->get(route('assistant.show'))->assertNotFound();
        $this->actingAs($this->user)->post(route('assistant.ask'), ['pregunta' => 'Hola'])->assertNotFound();
        $this->actingAs($this->user)->post(route('assistant.clear'))->assertNotFound();
        $this->actingAs($this->user)->post(route('assistant.draft.publish'))->assertNotFound();
        $this->actingAs($this->user)->post(route('assistant.draft.discard'))->assertNotFound();
        $this->assertSame([], $this->model->requests);

        $this->actingAs($this->user)->get(route('people.index'))->assertOk()->assertDontSee(route('assistant.show'));
        $this->actingAs($this->user)->get(route('home'))->assertOk()->assertDontSee(route('assistant.show'));
    }

    public function test_guests_go_to_the_sign_in(): void
    {
        $this->get(route('assistant.show'))->assertRedirect(route('login'));
    }

    public function test_the_brand_bar_and_the_home_menu_link_to_it_while_it_is_on(): void
    {
        $this->actingAs($this->user)->get(route('people.index'))->assertOk()->assertSee(route('assistant.show'));
        $this->actingAs($this->user)->get(route('home'))->assertOk()->assertSee(route('assistant.show'));
    }

    public function test_the_empty_page_offers_suggestions_and_the_daily_count(): void
    {
        $this->actingAs($this->user)->get(route('assistant.show'))
            ->assertOk()
            ->assertSeeText('Asistente')
            ->assertSeeText('Pregúntale por lo que ya ves en el portal en Segurex')
            ->assertSeeText('¿Hay anuncios que deba leer?')
            ->assertSeeText('Te quedan 40 preguntas de 40 por hoy.')
            ->assertDontSee('Livewire')
            ->assertDontSee('x-data');
    }

    public function test_without_the_api_key_the_page_says_it_is_not_available_and_nothing_is_sent(): void
    {
        config(['services.anthropic.key' => null]);

        $this->actingAs($this->user)->get(route('assistant.show'))
            ->assertOk()
            ->assertSeeText('El asistente no está disponible en este momento.')
            ->assertDontSee('name="pregunta"', false);

        $this->ask('Hola')->assertRedirect(route('assistant.show'));
        $this->assertSame([], $this->model->requests);
        $this->assertSame(0, Activity::query()->where('event', AssistantLog::ASKED)->count());
    }

    public function test_a_question_is_answered_and_shown_after_the_redirect(): void
    {
        $this->model->answers("Tienes 2 tareas.\nMira https://portal.gruporyr.com/anuncios/5. O https://evil.example/x");

        $this->ask('  ¿Qué tengo pendiente?  ')->assertRedirect(route('assistant.show').'#respuesta');

        $this->assertCount(1, $this->model->requests[0]->messages);
        $this->assertSame(['type' => 'text', 'text' => '¿Qué tengo pendiente?'], $this->model->requests[0]->messages[0]['content'][1]);
        $this->actingAs($this->user)->get(route('assistant.show'))
            ->assertOk()
            ->assertSeeText('¿Qué tengo pendiente?')
            ->assertSee('Tienes 2 tareas.<br>', false)
            ->assertSee('<a href="https://portal.gruporyr.com/anuncios/5" class="', false)
            ->assertDontSee('href="https://evil.example/x"', false)
            ->assertSeeText('Te quedan 39 preguntas de 40 por hoy.');
        $this->assertSame(1, Activity::query()->where('event', AssistantLog::ASKED)->count());
    }

    public function test_an_answer_never_renders_markup(): void
    {
        $this->model->answers('<script>alert(1)</script><b>hola</b>');

        $this->ask('Hola');

        $this->actingAs($this->user)->get(route('assistant.show'))
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_the_question_is_required_and_bounded(): void
    {
        config(['assistant.question_max_length' => 10]);

        $this->ask('   ')->assertSessionHasErrors(['pregunta' => 'Escribe tu pregunta.']);
        $this->ask(str_repeat('a', 11))->assertSessionHasErrors(['pregunta' => 'Usa como máximo 10 caracteres.']);
        $this->assertSame([], $this->model->requests);
    }

    public function test_the_daily_cap_stops_before_the_model(): void
    {
        config(['assistant.daily_questions' => 1]);
        $this->model->answers('Primera.');

        $this->ask('Uno')->assertSessionHasNoErrors();
        $this->ask('Dos')->assertSessionHasErrors(['pregunta' => 'Llegaste al límite de 1 preguntas de hoy. Mañana puedes seguir.']);

        $this->assertCount(1, $this->model->requests);
        $this->assertSame(1, Activity::query()->where('event', AssistantLog::ASKED)->count());
        $this->actingAs($this->user)->get(route('assistant.show'))->assertSeeText('Te quedan 0 preguntas de 1 por hoy.');

        // A new day in Bogotá.
        $this->travelTo(CarbonImmutable::parse('2026-11-05 00:05', 'America/Bogota'));
        $this->model->answers('Segunda.');
        $this->ask('Dos')->assertSessionHasNoErrors();
    }

    public function test_a_failure_keeps_the_question_and_adds_nothing(): void
    {
        $this->model->fails('timeout');

        $this->ask('¿Qué tengo?')
            ->assertRedirect(route('assistant.show'))
            ->assertSessionHasErrors(['pregunta' => Assistant::UNAVAILABLE])
            ->assertSessionHasInput('pregunta', '¿Qué tengo?');

        $this->assertSame([], (new Conversation(session()->driver(), $this->user))->turns());
        $this->assertSame('timeout', Activity::query()->where('event', AssistantLog::ASKED)->sole()->properties['error']);
    }

    public function test_the_next_question_carries_the_conversation_and_a_new_one_clears_it(): void
    {
        $this->model->answers('R1')->answers('R2');
        $this->ask('P1');
        $this->ask('P2');

        $messages = $this->model->requests[1]->messages;
        $this->assertSame(['user', 'assistant', 'user'], array_column($messages, 'role'));
        $this->assertSame('P1', $messages[0]['content'][1]['text']);
        $this->assertSame('R1', $messages[1]['content'][1]['text']);
        $this->assertSame('P2', $messages[2]['content'][1]['text']);

        $this->actingAs($this->user)->post(route('assistant.clear'))->assertRedirect(route('assistant.show'));
        $this->actingAs($this->user)->get(route('assistant.show'))
            ->assertSeeText('Empezaste una conversación nueva.')
            ->assertDontSeeText('P1');
    }

    public function test_another_account_on_the_same_session_never_sees_the_conversation(): void
    {
        $this->model->answers('Respuesta para Jhon');
        $this->ask('Pregunta de Jhon');

        $other = User::factory()->microsoft($this->user->company)->create();

        $this->actingAs($other)->get(route('assistant.show'))
            ->assertOk()
            ->assertDontSeeText('Pregunta de Jhon')
            ->assertDontSeeText('Respuesta para Jhon');
    }

    public function test_a_full_conversation_offers_a_new_one_and_never_calls_the_model(): void
    {
        config(['assistant.conversation_turns' => 1]);
        $this->model->answers('R1');
        $this->ask('P1');

        $this->ask('P2')->assertSessionHasErrors(['pregunta' => 'Esta conversación está llena. Empieza una «Nueva conversación» para seguir preguntando.']);

        $this->assertCount(1, $this->model->requests);
        $this->actingAs($this->user)->get(route('assistant.show'))
            ->assertSeeText('Esta conversación llegó a su límite de 1 preguntas.')
            ->assertSeeText('Nueva conversación');
    }

    public function test_a_role_change_starts_a_new_conversation_with_a_notice(): void
    {
        $this->model->answers('R1');
        $this->ask('P1');
        $this->user->assignRole('announcements_editor');
        $this->user = $this->user->fresh();

        $this->actingAs($this->user)->get(route('assistant.show'))
            ->assertSeeText('Empezamos una conversación nueva porque cambió tu cuenta o la configuración del asistente.')
            ->assertDontSeeText('R1');
    }

    public function test_signing_out_ends_the_conversation(): void
    {
        $this->model->answers('R1');
        $this->ask('P1');

        $this->actingAs($this->user)->post(route('logout'));

        $this->assertNull(session(Conversation::KEY));
    }
}
