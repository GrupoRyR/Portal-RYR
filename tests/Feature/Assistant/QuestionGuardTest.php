<?php

namespace Tests\Feature\Assistant;

use App\Assistant\Answer;
use App\Assistant\Assistant;
use App\Assistant\AssistantLog;
use App\Assistant\Conversation;
use App\Assistant\Model\AssistantModel;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The limits checked before every model call of a question (review M1,
 * M2): the month's budget including what the question already cost, an
 * overall deadline, usage audited even when a call throws, and no empty
 * assistant turn kept (L1).
 */
class QuestionGuardTest extends TestCase
{
    use RefreshDatabase;

    private FakeAssistantModel $model;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00', 'America/Bogota'));
        config([
            'assistant.model' => 'anthropic:claude-haiku-5-5',
            'assistant.fallback_model' => '',
            'assistant.secondary_model' => '',
            'assistant.monthly_budget_usd' => 20.0,
            'assistant.deadline_seconds' => 90,
        ]);

        $this->model = new FakeAssistantModel;
        $this->app->instance(AssistantModel::class, $this->model);

        $this->user = User::factory()->microsoft(Company::factory()->create(['short_name' => 'Segurex']))->create();
    }

    private function conversation(): Conversation
    {
        return new Conversation($this->app['session.store'], $this->user);
    }

    private function ask(string $question = '¿Qué apps tengo?'): Answer
    {
        return app(Assistant::class)->ask($this->user, $this->conversation(), $question);
    }

    private function lastLine(): Activity
    {
        return Activity::query()->where('event', AssistantLog::ASKED)->latest('id')->firstOrFail();
    }

    public function test_a_tool_loop_stops_after_the_call_that_crosses_the_budget(): void
    {
        activity('security')->event(AssistantLog::ASKED)->causedBy($this->user)
            ->withProperties(['cost_usd' => 19.99])->log(AssistantLog::ASKED);
        // 40,000 output tokens of Haiku 5.5: US$ 0.02, so 19.99 + 0.02 crosses 20.
        $this->model->withUsage(0, 40_000)->callsTool('mis_apps')->answers('Nunca llega.');

        $answer = $this->ask();

        $this->assertCount(1, $this->model->requests, 'No call after the one that crossed the line.');
        $this->assertSame(Assistant::BUDGET_REACHED, $answer->text);
        $this->assertSame('budget', $this->lastLine()->properties['stopped']);
        $this->assertEqualsWithDelta(0.02, $this->lastLine()->properties['cost_usd'], 1e-9);
        $this->assertSame(1, Activity::query()->where('event', AssistantLog::BUDGET_BLOCKED)->count());

        // The kept history is valid: the tool call has its result, then a plain assistant text.
        $kept = $this->conversation()->transcript();
        $this->assertSame(['user', 'assistant', 'user', 'assistant'], array_column($kept, 'role'));
        $this->assertSame('tool_result', $kept[2]['content'][0]['type']);
        $this->assertSame([['type' => 'text', 'text' => Assistant::BUDGET_REACHED]], $kept[3]['content']);
    }

    public function test_the_deadline_stops_the_loop_and_each_call_gets_the_time_left(): void
    {
        $this->model->onSend(fn () => $this->travel(100)->seconds())->callsTool('mis_apps')->answers('Nunca llega.');

        $answer = $this->ask();

        $this->assertSame(Assistant::TOO_SLOW, $answer->text);
        $this->assertCount(1, $this->model->requests);
        $this->assertSame(90, $this->model->requests[0]->timeout);
        $this->assertSame('deadline', $this->lastLine()->properties['stopped']);
        $kept = $this->conversation()->transcript();
        $this->assertSame([['type' => 'text', 'text' => Assistant::TOO_SLOW]], end($kept)['content']);
    }

    public function test_the_usage_is_audited_even_when_a_later_call_throws(): void
    {
        $this->model->withUsage(0, 40_000)->callsTool('mis_apps')->throws(new RuntimeException('boom'));

        try {
            $this->ask();
            $this->fail('Expected the exception.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $line = $this->lastLine();
        $this->assertSame('exception', $line->properties['error']);
        $this->assertSame(1, $line->properties['model_calls']);
        $this->assertEqualsWithDelta(0.02, $line->properties['cost_usd'], 1e-9);
        $this->assertSame(1, AssistantLog::questionsToday($this->user));
    }

    public function test_an_empty_assistant_turn_is_never_kept(): void
    {
        $this->model->emptyTurn()->answers('Segunda.');

        $this->assertSame(Assistant::EMPTY, $this->ask()->text);
        $kept = $this->conversation()->transcript();
        $this->assertSame(['role' => 'assistant', 'content' => [['type' => 'text', 'text' => Assistant::EMPTY]]], end($kept));

        // The next request carries no empty assistant message.
        $this->ask('Otra');
        foreach ($this->model->requests[1]->messages as $message) {
            $this->assertNotSame([], $message['content']);
        }
    }
}
