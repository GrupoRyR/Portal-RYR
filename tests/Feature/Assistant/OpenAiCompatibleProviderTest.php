<?php

namespace Tests\Feature\Assistant;

use App\Assistant\Answer;
use App\Assistant\Assistant;
use App\Assistant\AssistantLog;
use App\Assistant\AssistantSwitch;
use App\Assistant\Conversation;
use App\Assistant\Model\ModelRequest;
use App\Assistant\Model\ModelUnavailable;
use App\Assistant\Model\ProviderRegistry;
use App\Livewire\Admin\AssistantToggle;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The `openai_compatible` driver (DeepSeek preset) through the real
 * provider registry and Laravel's HTTP client, faked: the tool loop in
 * OpenAI's format, the usage mapping, `content_filter` as a refusal, no
 * Anthropic-only parameter, and a missing key as «no disponible».
 */
class OpenAiCompatibleProviderTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://api.deepseek.com/chat/completions';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->seed(RoleSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00', 'America/Bogota'));
        config([
            'assistant.enabled' => true,
            'assistant.model' => 'deepseek:deepseek-chat',
            'assistant.editor_model' => 'deepseek:deepseek-chat',
            'assistant.fallback_model' => 'deepseek:deepseek-reasoner',
            'assistant.providers.deepseek.api_key' => 'sk-deepseek-test',
            'services.anthropic.key' => null,
        ]);
        DB::table('portal_settings')->insert(['key' => AssistantSwitch::SETTING, 'value' => 'true']);

        $company = Company::factory()->create(['short_name' => 'Segurex']);
        $this->user = User::factory()->microsoft($company)->create(['name' => 'Jhon Castro']);
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, int>  $usage
     */
    private static function completion(array $message, string $finish = 'stop', array $usage = ['prompt_tokens' => 100, 'completion_tokens' => 20]): array
    {
        return [
            'id' => 'chatcmpl-1',
            'object' => 'chat.completion',
            'model' => 'deepseek-chat',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', ...$message], 'finish_reason' => $finish]],
            'usage' => $usage,
        ];
    }

    private function ask(string $question = '¿Qué apps tengo?'): Answer
    {
        return app(Assistant::class)->ask($this->user, new Conversation($this->app['session.store'], $this->user), $question);
    }

    public function test_the_tool_loop_runs_in_openai_format_without_anthropic_only_parameters(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->push(self::completion(['content' => null, 'tool_calls' => [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'mis_apps', 'arguments' => '{}']],
            ]], 'tool_calls'))
            ->push(self::completion(['content' => 'No tienes apps todavía.', 'reasoning_content' => 'pensando...']))]);

        $answer = $this->ask();

        $this->assertSame('No tienes apps todavía.', $answer->text);
        $this->assertSame(['mis_apps'], $answer->tools);

        $sent = Http::recorded()->map(fn (array $pair) => $pair[0])->values();
        $this->assertCount(2, $sent);

        /** @var Request $first */
        $first = $sent[0];
        $this->assertSame('Bearer sk-deepseek-test', $first->header('Authorization')[0]);
        $this->assertSame('deepseek-chat', $first['model']);
        $this->assertSame(2000, $first['max_tokens']);
        $this->assertSame('system', $first['messages'][0]['role']);
        $this->assertSame('user', $first['messages'][1]['role']);
        $this->assertStringContainsString('¿Qué apps tengo?', $first['messages'][1]['content']);
        $this->assertSame('function', $first['tools'][0]['type']);
        $this->assertContains('mis_apps', array_column(array_column($first['tools'], 'function'), 'name'));
        foreach (['thinking', 'output_config', 'cache_control', 'system', 'temperature'] as $anthropicOnly) {
            $this->assertArrayNotHasKey($anthropicOnly, $first->data());
        }
        $this->assertStringNotContainsString('cache_control', $first->body());

        /** @var Request $second */
        $second = $sent[1];
        $this->assertSame(['role' => 'assistant', 'content' => null, 'tool_calls' => [
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'mis_apps', 'arguments' => '{}']],
        ]], $second['messages'][2]);
        $this->assertSame(['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"apps":[],"limite":30}'], $second['messages'][3]);

        // The provider's reasoning text is not kept.
        $this->assertStringNotContainsString('pensando', json_encode((new Conversation($this->app['session.store'], $this->user))->transcript()));
    }

    public function test_usage_maps_to_the_same_fields_and_is_priced_by_provider_and_model(): void
    {
        Http::fake([self::URL => Http::response(self::completion(['content' => 'Hola.'], usage: [
            'prompt_tokens' => 1000, 'completion_tokens' => 50, 'prompt_cache_hit_tokens' => 800, 'prompt_cache_miss_tokens' => 200,
        ]))]);

        $this->ask();

        $line = Activity::query()->where('event', AssistantLog::ASKED)->sole();
        $this->assertSame('deepseek', $line->properties['provider']);
        $this->assertSame('deepseek-chat', $line->properties['model']);
        $this->assertSame(200, $line->properties['input_tokens']);
        $this->assertSame(800, $line->properties['cache_read_input_tokens']);
        $this->assertSame(0, $line->properties['cache_creation_input_tokens']);
        $this->assertSame(50, $line->properties['output_tokens']);
        $prices = config('assistant.prices')['deepseek:deepseek-chat'];
        $this->assertEqualsWithDelta((200 * $prices['input'] + 800 * $prices['cache_read'] + 50 * $prices['output']) / 1_000_000, $line->properties['cost_usd'], 1e-6);
    }

    public function test_without_cache_fields_prompt_tokens_are_the_input(): void
    {
        Http::fake([self::URL => Http::response(self::completion(['content' => 'Hola.'], usage: ['prompt_tokens' => 321, 'completion_tokens' => 9]))]);

        $this->ask();

        $line = Activity::query()->where('event', AssistantLog::ASKED)->sole();
        $this->assertSame([321, 0, 9], [$line->properties['input_tokens'], $line->properties['cache_read_input_tokens'], $line->properties['output_tokens']]);
    }

    public function test_content_filter_is_a_refusal_asked_again_to_the_fallback(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->push(self::completion(['content' => ''], 'content_filter'))
            ->push(self::completion(['content' => 'Respuesta del respaldo.']))]);

        $answer = $this->ask();

        $this->assertSame('Respuesta del respaldo.', $answer->text);
        $this->assertTrue($answer->fallback);
        $models = Http::recorded()->map(fn (array $pair) => $pair[0]['model'])->values()->all();
        $this->assertSame(['deepseek-chat', 'deepseek-reasoner'], $models);
    }

    public function test_a_missing_key_is_no_disponible_and_nothing_is_sent(): void
    {
        config(['assistant.providers.deepseek.api_key' => null]);
        Http::fake();

        $this->assertFalse(app(AssistantSwitch::class)->configured());
        $this->actingAs($this->user)->get(route('assistant.show'))->assertSeeText('El asistente no está disponible en este momento.');

        try {
            app(ProviderRegistry::class)->send(new ModelRequest('deepseek-chat', 'low', 2000, 's', [], [], 'deepseek'));
            $this->fail('Expected ModelUnavailable.');
        } catch (ModelUnavailable $e) {
            $this->assertSame('not_configured', $e->reason);
        }

        Http::assertNothingSent();
    }

    public function test_only_https_base_urls_are_called(): void
    {
        config(['assistant.providers.deepseek.base_url' => 'http://api.deepseek.com']);
        Http::fake();

        $this->assertSame(Assistant::UNAVAILABLE, $this->ask()->text);
        Http::assertNothingSent();
    }

    public function test_http_errors_become_short_reasons(): void
    {
        $cases = [401 => 'auth', 402 => 'billing', 400 => 'http_400'];
        $sequence = Http::sequence();
        foreach (array_keys($cases) as $status) {
            $sequence->push(['error' => ['message' => 'secret detail']], $status);
        }
        Http::fake([self::URL => $sequence]);

        foreach ($cases as $status => $reason) {

            try {
                app(ProviderRegistry::class)->send(new ModelRequest('deepseek-chat', 'low', 2000, 's', [], [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hola']]]], 'deepseek'));
                $this->fail("Expected {$reason}.");
            } catch (ModelUnavailable $e) {
                $this->assertSame($reason, $e->reason);
                $this->assertStringNotContainsString('secret detail', $e->getMessage());
            }
        }
    }

    public function test_configuracion_warns_when_a_role_leaves_anthropic(): void
    {
        $admin = User::factory()->microsoft($this->user->company)->create();
        $admin->assignRole('super_admin');

        Livewire::actingAs($admin)->test(AssistantToggle::class)
            ->assertSeeText('deepseek:deepseek-chat')
            ->assertSeeText('Este proveedor procesa los datos fuera de Anthropic; revisa la Ley 1581 (transferencia internacional) antes de activarlo.');

        config(['assistant.model' => 'anthropic:claude-haiku-5-5', 'assistant.editor_model' => 'anthropic:claude-sonnet-5-5', 'assistant.fallback_model' => 'anthropic:claude-opus-5-5']);
        Livewire::actingAs($admin)->test(AssistantToggle::class)
            ->assertDontSeeText('Este proveedor procesa los datos fuera de Anthropic');
    }

    public function test_a_provider_change_starts_a_new_conversation(): void
    {
        Http::fake([self::URL => Http::response(self::completion(['content' => 'Hola.']))]);
        $this->ask();
        $conversation = new Conversation($this->app['session.store'], $this->user);
        $this->assertSame('deepseek:deepseek-chat', $conversation->model());

        config(['assistant.model' => 'anthropic:claude-haiku-5-5']);

        $this->assertTrue(app(Assistant::class)->sync($this->user, $conversation));
        $this->assertSame('anthropic:claude-haiku-5-5', $conversation->model());
    }
}
