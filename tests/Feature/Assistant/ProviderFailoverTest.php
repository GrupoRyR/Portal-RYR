<?php

namespace Tests\Feature\Assistant;

use App\Assistant\Answer;
use App\Assistant\Assistant;
use App\Assistant\AssistantLog;
use App\Assistant\AssistantSwitch;
use App\Assistant\Conversation;
use App\Assistant\Failover\PrimaryDownNotice;
use App\Assistant\Failover\ProviderFailover;
use App\Assistant\Model\AssistantModel;
use App\Http\Controllers\Assistant\AssistantController;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Provider failover: when the primary (Anthropic) fails for a reason that
 * fails over, the conversation moves to the secondary (DeepSeek here) as a
 * text-only transcript, for the rest of its life; the breaker skips the
 * primary during its cooldown; billing and credentials alert the super
 * admins once; bugs (400) and the monthly budget never fail over.
 */
class ProviderFailoverTest extends TestCase
{
    use RefreshDatabase;

    private FakeAssistantModel $model;

    private User $user;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00', 'America/Bogota'));
        config([
            'assistant.enabled' => true,
            'services.anthropic.key' => 'sk-ant-test',
            'assistant.providers.deepseek.api_key' => 'sk-deepseek-test',
            'assistant.model' => 'anthropic:claude-haiku-5-5',
            'assistant.editor_model' => 'anthropic:claude-sonnet-5-5',
            'assistant.fallback_model' => 'anthropic:claude-opus-5-5',
            'assistant.secondary_model' => 'deepseek:deepseek-chat',
            'assistant.secondary_editor_model' => '',
            'assistant.primary_cooldown_minutes' => 30,
            'portal.super_admin_emails' => [],
        ]);
        DB::table('portal_settings')->insert(['key' => AssistantSwitch::SETTING, 'value' => 'true']);

        $this->model = new FakeAssistantModel;
        $this->app->instance(AssistantModel::class, $this->model);

        $company = Company::factory()->create(['short_name' => 'Segurex']);
        $this->user = User::factory()->microsoft($company)->create();
        $this->admin = User::factory()->microsoft($company)->create(['email' => 'admin@segurex.com']);
        $this->admin->assignRole('super_admin');
    }

    private function conversation(): Conversation
    {
        return new Conversation($this->app['session.store'], $this->user);
    }

    private function ask(string $question = 'Hola'): Answer
    {
        return app(Assistant::class)->ask($this->user, $this->conversation(), $question);
    }

    /**
     * @return list<string>
     */
    private function providers(): array
    {
        return array_map(fn ($request) => $request->provider.':'.$request->model, $this->model->requests);
    }

    public function test_a_billing_error_moves_the_conversation_to_the_secondary_and_alerts_once(): void
    {
        $this->model->fails('billing')->answers('Desde DeepSeek.', 'deepseek-chat')->answers('Sigo en DeepSeek.', 'deepseek-chat');

        $answer = $this->ask();

        $this->assertSame('Desde DeepSeek.', $answer->text);
        $this->assertTrue($answer->failover);
        $this->assertSame(['anthropic:claude-haiku-5-5', 'deepseek:deepseek-chat'], $this->providers());
        $this->assertTrue($this->conversation()->moved());
        $this->assertSame('deepseek:deepseek-chat', $this->conversation()->model());

        $failover = Activity::query()->where('event', AssistantLog::FAILOVER)->sole();
        $this->assertSame(['from' => 'anthropic:claude-haiku-5-5', 'to' => 'deepseek:deepseek-chat', 'reason' => 'billing'], $failover->properties->all());
        $line = Activity::query()->where('event', AssistantLog::ASKED)->sole();
        $this->assertSame('deepseek', $line->properties['provider']);
        $this->assertTrue($line->properties['failover']);

        Notification::assertSentTo($this->admin, PrimaryDownNotice::class);
        Notification::assertSentTimes(PrimaryDownNotice::class, 1);

        // It never goes back mid-conversation.
        $this->ask('Otra');
        $this->assertSame('deepseek:deepseek-chat', $this->providers()[2]);
        $this->actingAs($this->user)->get(route('assistant.show'))->assertSeeText('Respondiendo con el proveedor secundario.');
    }

    public function test_the_secondary_gets_a_text_only_transcript(): void
    {
        $this->model->callsTool('mis_apps')->answers('No tienes apps.')->fails('overloaded')->answers('Hola desde DeepSeek.', 'deepseek-chat');
        $this->ask('¿Qué apps tengo?');

        $this->ask('¿Y anuncios?');

        $secondary = $this->model->requests[3];
        $this->assertSame('deepseek', $secondary->provider);
        $json = json_encode($secondary->messages);
        $this->assertStringNotContainsString('thinking', $json);
        $this->assertStringNotContainsString('signature', $json);
        $this->assertStringNotContainsString('tool_use', $json);
        $this->assertStringNotContainsString('tool_result', $json);
        $this->assertSame([
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => '¿Qué apps tengo?']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'No tienes apps.']]],
        ], array_slice($secondary->messages, 0, 2));
        $this->assertSame('¿Y anuncios?', $secondary->messages[2]['content'][1]['text']);
    }

    public function test_during_the_cooldown_new_conversations_skip_the_primary(): void
    {
        $this->model->fails('auth')->answers('Uno.', 'deepseek-chat')->answers('Dos.', 'deepseek-chat')->answers('Tres.');
        $this->ask();

        $this->conversation()->clear();
        $this->ask();
        $this->assertSame('deepseek:deepseek-chat', $this->providers()[2], 'No failed call to the primary first.');

        // After the cooldown, a new conversation tries the primary again.
        $this->travel(31)->minutes();
        $this->conversation()->clear();
        $this->ask();
        $this->assertSame('anthropic:claude-haiku-5-5', $this->providers()[3]);
        Notification::assertSentTimes(PrimaryDownNotice::class, 1);
    }

    public function test_a_rate_limit_skips_the_primary_for_two_minutes_without_an_alert(): void
    {
        $this->model->fails('rate_limited')->answers('Uno.', 'deepseek-chat');
        $this->ask();

        $failover = app(ProviderFailover::class);
        $this->assertTrue($failover->isDown('anthropic'));
        $this->travel(3)->minutes();
        $this->assertFalse($failover->isDown('anthropic'));
        Notification::assertNothingSent();
    }

    public function test_a_bad_request_never_fails_over(): void
    {
        Log::spy();
        $this->model->fails('http_400');

        $answer = $this->ask();

        $this->assertSame(Assistant::UNAVAILABLE, $answer->text);
        $this->assertSame(['anthropic:claude-haiku-5-5'], $this->providers());
        $this->assertFalse($this->conversation()->moved());
        $this->assertSame(0, Activity::query()->where('event', AssistantLog::FAILOVER)->count());
        $this->assertFalse(app(ProviderFailover::class)->isDown('anthropic'));
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $context['reason'] === 'http_400')->once();
    }

    public function test_when_both_providers_fail_the_page_says_it_is_not_available(): void
    {
        $this->model->fails('connection')->fails('http_503');

        $this->actingAs($this->user)->post(route('assistant.ask'), ['pregunta' => 'Hola'])
            ->assertSessionHasErrors(['pregunta' => Assistant::BOTH_UNAVAILABLE]);

        $line = Activity::query()->where('event', AssistantLog::ASKED)->sole();
        $this->assertSame('http_503', $line->properties['error']);
        $this->assertTrue($line->properties['failover']);
        $this->assertSame(1, Activity::query()->where('event', AssistantLog::FAILOVER)->count());
    }

    public function test_the_monthly_budget_never_fails_over(): void
    {
        config(['assistant.monthly_budget_usd' => 1.0]);
        activity('security')->event(AssistantLog::ASKED)->causedBy($this->user)->withProperties(['cost_usd' => 1.0])->log(AssistantLog::ASKED);

        $this->actingAs($this->user)->post(route('assistant.ask'), ['pregunta' => 'Hola'])
            ->assertSessionHasErrors(['pregunta' => AssistantController::BUDGET_REACHED]);

        $this->assertSame([], $this->model->requests);
        $this->assertSame(0, Activity::query()->where('event', AssistantLog::FAILOVER)->count());
    }

    public function test_without_a_secondary_the_behavior_is_unchanged(): void
    {
        config(['assistant.secondary_model' => '']);
        $this->model->fails('billing');

        $answer = $this->ask();

        $this->assertSame(Assistant::UNAVAILABLE, $answer->text);
        $this->assertCount(1, $this->model->requests);
        $this->assertFalse(app(ProviderFailover::class)->isDown('anthropic'));
        Notification::assertNothingSent();
    }

    public function test_editors_fail_over_to_their_own_secondary_or_the_shared_one(): void
    {
        $this->user->assignRole('announcements_editor');
        $this->user = $this->user->fresh();
        $this->model->fails('billing')->answers('ok', 'deepseek-chat');
        $this->ask();
        $this->assertSame(['anthropic:claude-sonnet-5-5', 'deepseek:deepseek-chat'], $this->providers());

        config(['assistant.secondary_editor_model' => 'deepseek:deepseek-reasoner']);
        $this->conversation()->clear();
        $this->model->answers('ok', 'deepseek-reasoner');
        $this->ask();
        $this->assertSame('deepseek:deepseek-reasoner', $this->providers()[2], 'Still in the cooldown: straight to the secondary.');
    }

    public function test_a_missing_primary_key_is_served_by_the_secondary(): void
    {
        config(['services.anthropic.key' => null]);

        $this->assertTrue(app(AssistantSwitch::class)->configured());

        config(['assistant.secondary_model' => '']);
        $this->assertFalse(app(AssistantSwitch::class)->configured());
    }
}
