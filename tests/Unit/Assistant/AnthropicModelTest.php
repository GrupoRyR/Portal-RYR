<?php

namespace Tests\Unit\Assistant;

use App\Assistant\Model\AnthropicModel;
use App\Assistant\Model\ModelRequest;
use App\Assistant\Model\ModelTurn;
use App\Assistant\Model\ModelUnavailable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * The SDK implementation of the assistant's model, against a mocked HTTP
 * client (Guzzle's MockHandler): what goes on the wire (cache breakpoints,
 * thinking, effort, the transcript sent back byte for byte) and how the
 * answer is read back. No request ever leaves the machine.
 */
class AnthropicModelTest extends TestCase
{
    /** @var list<array{request: RequestInterface}> */
    private array $sent = [];

    /**
     * @param  list<Response|\Throwable>  $responses
     */
    private function model(array $responses, ?string $key = 'sk-ant-test'): AnthropicModel
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->sent));

        return new AnthropicModel($key, 30, new Client(['handler' => $stack]));
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    private function request(array $messages = [], string $model = 'claude-haiku-5-5'): ModelRequest
    {
        return new ModelRequest(
            model: $model,
            effort: 'low',
            maxTokens: 2000,
            system: 'Instrucciones estables.',
            tools: [
                ['name' => 'mis_tareas', 'description' => 'Las tareas pendientes.', 'inputSchema' => ['type' => 'object', 'properties' => (object) []]],
                ['name' => 'borrador_de_anuncio', 'description' => 'Propone.', 'inputSchema' => ['type' => 'object', 'properties' => ['titulo' => ['type' => 'string']], 'required' => ['titulo'], 'additionalProperties' => false], 'strict' => true],
            ],
            messages: $messages ?: [self::question('¿Qué tareas tengo?')],
        );
    }

    /**
     * @return array{role: string, content: list<array<string, mixed>>}
     */
    private static function question(string $text): array
    {
        return ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Contexto: hoy.'], ['type' => 'text', 'text' => $text]]];
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @param  array<string, int>  $usage
     */
    private static function answer(array $content, string $stopReason, array $usage = ['input_tokens' => 100, 'output_tokens' => 20], string $model = 'claude-haiku-5-5'): Response
    {
        return new Response(200, ['Content-Type' => 'application/json', 'request-id' => 'req_1'], json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => $model,
            'content' => $content,
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'usage' => $usage,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function body(int $index): array
    {
        return json_decode((string) $this->sent[$index]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function toolTurn(): array
    {
        return [
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-1'],
            ['type' => 'text', 'text' => 'Voy a mirar.'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'mis_tareas', 'input' => (object) [], 'caller' => ['type' => 'direct']],
        ];
    }

    public function test_it_sends_a_cached_static_prefix_and_reads_tool_calls_and_usage(): void
    {
        $turn = $this->model([self::answer(self::toolTurn(), 'tool_use', [
            'input_tokens' => 12, 'output_tokens' => 20, 'cache_read_input_tokens' => 900, 'cache_creation_input_tokens' => 50,
        ])])->send($this->request());

        $this->assertSame(ModelTurn::TOOL_USE, $turn->stopReason);
        $this->assertSame('Voy a mirar.', $turn->text);
        $this->assertSame(['toolu_1', 'mis_tareas', []], [$turn->toolCalls[0]->id, $turn->toolCalls[0]->name, $turn->toolCalls[0]->input]);
        $this->assertSame([12, 20, 50, 900], [$turn->inputTokens, $turn->outputTokens, $turn->cacheCreationInputTokens, $turn->cacheReadInputTokens]);
        $this->assertSame('claude-haiku-5-5', $turn->model);

        $request = $this->sent[0]['request'];
        $this->assertSame('https://api.anthropic.com/v1/messages', (string) $request->getUri());
        // The SDK sends the API key as a bearer token.
        $this->assertSame('Bearer sk-ant-test', $request->getHeaderLine('Authorization'));

        $body = $this->body(0);
        $this->assertSame('claude-haiku-5-5', $body['model']);
        $this->assertSame(2000, $body['max_tokens']);
        $this->assertSame(['effort' => 'low'], $body['output_config']);
        $this->assertSame(['type' => 'adaptive'], $body['thinking']);
        $this->assertSame([['type' => 'text', 'text' => 'Instrucciones estables.', 'cache_control' => ['type' => 'ephemeral']]], $body['system']);
        // The moving breakpoint: the last block of the latest user message.
        $this->assertSame([
            ['type' => 'text', 'text' => 'Contexto: hoy.'],
            ['type' => 'text', 'text' => '¿Qué tareas tengo?', 'cache_control' => ['type' => 'ephemeral']],
        ], $body['messages'][0]['content']);
        $this->assertSame(['mis_tareas', 'borrador_de_anuncio'], array_column($body['tools'], 'name'));
        $this->assertArrayNotHasKey('strict', $body['tools'][0]);
        $this->assertTrue($body['tools'][1]['strict']);
        // Each of these is a 400 on Haiku 5.5, Sonnet 5.5 or Opus 5.5.
        foreach (['temperature', 'top_p', 'top_k', 'tool_choice'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $body);
        }
        $this->assertArrayNotHasKey('budget_tokens', $body['thinking']);
        $this->assertStringContainsString('"properties":{}', (string) $request->getBody());
    }

    public function test_the_kept_turn_goes_back_byte_for_byte_after_a_json_session(): void
    {
        $model = $this->model([
            self::answer(self::toolTurn(), 'tool_use'),
            self::answer([['type' => 'text', 'text' => 'Tienes una tarea.']], 'end_turn'),
        ]);

        $first = $model->send($this->request());
        // The session stores JSON: the turn must survive it.
        $kept = json_decode(json_encode($first->message()), true);

        $second = $model->send($this->request([
            self::question('¿Qué tareas tengo?'),
            $kept,
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => '{"tareas":[]}', 'is_error' => false]]],
        ]));

        $this->assertSame('end_turn', $second->stopReason);
        $this->assertSame('Tienes una tarea.', $second->text);

        $sentBack = $this->body(1)['messages'];
        $original = json_decode(json_encode(self::toolTurn()), true);
        $this->assertEquals($original, $sentBack[1]['content']);
        $this->assertStringContainsString('"input":{}', (string) $this->sent[1]['request']->getBody());
        // The earlier question lost its breakpoint; the tool results carry it now.
        $this->assertArrayNotHasKey('cache_control', $sentBack[0]['content'][1]);
        $this->assertSame([
            'role' => 'user',
            'content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => '{"tareas":[]}', 'is_error' => false, 'cache_control' => ['type' => 'ephemeral']]],
        ], $sentBack[2]);
    }

    public function test_a_nested_empty_object_in_a_tool_input_goes_back_unchanged(): void
    {
        $model = $this->model([
            self::answer([
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-1'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'buscar_personas', 'input' => ['texto' => 'Ana', 'filtros' => (object) [], 'lista' => []], 'caller' => ['type' => 'direct']],
            ], 'tool_use'),
            self::answer([['type' => 'text', 'text' => 'Listo.']], 'end_turn'),
        ]);

        $first = $model->send($this->request());
        $kept = json_decode(json_encode($first->message()), true);
        $model->send($this->request([
            self::question('Busca a Ana'),
            $kept,
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => '{}', 'is_error' => false]]],
        ]));

        $body = (string) $this->sent[1]['request']->getBody();
        $this->assertStringContainsString('"input":{"texto":"Ana","filtros":{},"lista":[]}', $body);
        $this->assertStringNotContainsString('input_json', $body);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function models(): array
    {
        return ['reader' => ['claude-haiku-5-5'], 'editor' => ['claude-sonnet-5-5'], 'fallback' => ['claude-opus-5-5']];
    }

    #[DataProvider('models')]
    public function test_every_model_gets_the_same_safe_request_shape(string $name): void
    {
        $this->model([self::answer([['type' => 'text', 'text' => 'Hola.']], 'end_turn', model: $name)])->send($this->request(model: $name));

        $body = $this->body(0);
        $this->assertSame($name, $body['model']);
        $this->assertSame(['type' => 'adaptive'], $body['thinking']);
        $this->assertSame(['effort' => 'low'], $body['output_config']);
        $this->assertSame('user', end($body['messages'])['role'], 'No assistant prefill.');
    }

    public function test_a_refusal_and_a_cut_answer_come_back_as_their_stop_reason(): void
    {
        $model = $this->model([self::answer([], 'refusal'), self::answer([['type' => 'text', 'text' => 'A medias']], 'max_tokens')]);

        $this->assertSame(ModelTurn::REFUSAL, $model->send($this->request())->stopReason);
        $cut = $model->send($this->request());
        $this->assertSame(ModelTurn::MAX_TOKENS, $cut->stopReason);
        $this->assertSame('A medias', $cut->text);
    }

    public function test_without_a_key_nothing_is_sent(): void
    {
        try {
            $this->model([], key: '')->send($this->request());
            $this->fail('Expected ModelUnavailable.');
        } catch (ModelUnavailable $e) {
            $this->assertSame('not_configured', $e->reason);
        }

        $this->assertSame([], $this->sent);
    }

    public function test_api_errors_become_short_reasons_without_the_message(): void
    {
        $cases = [
            'auth' => [new Response(401, ['Content-Type' => 'application/json'], '{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}')],
            // By the error TYPE, after the SDK's own retry (429 and 529 are retried once).
            'billing' => [new Response(402, ['Content-Type' => 'application/json'], '{"type":"error","error":{"type":"billing_error","message":"credit balance is too low"}}')],
            'rate_limited' => [new Response(429, ['Content-Type' => 'application/json', 'retry-after' => '0'], '{"type":"error","error":{"type":"rate_limit_error","message":"slow down"}}'), new Response(429, ['Content-Type' => 'application/json', 'retry-after' => '0'], '{"type":"error","error":{"type":"rate_limit_error","message":"slow down"}}')],
            'overloaded' => [new Response(529, ['Content-Type' => 'application/json', 'retry-after' => '0'], '{"type":"error","error":{"type":"overloaded_error","message":"busy"}}'), new Response(529, ['Content-Type' => 'application/json', 'retry-after' => '0'], '{"type":"error","error":{"type":"overloaded_error","message":"busy"}}')],
            'http_400' => [new Response(400, ['Content-Type' => 'application/json'], '{"type":"error","error":{"type":"invalid_request_error","message":"bad"}}')],
            'connection' => [
                new ConnectException('down', new Request('POST', 'https://api.anthropic.com/v1/messages')),
                new ConnectException('down', new Request('POST', 'https://api.anthropic.com/v1/messages')),
            ],
        ];

        foreach ($cases as $reason => $responses) {
            try {
                $this->model($responses)->send($this->request());
                $this->fail("Expected {$reason}.");
            } catch (ModelUnavailable $e) {
                $this->assertSame($reason, $e->reason);
                $this->assertStringNotContainsString('invalid x-api-key', $e->getMessage());
            }
        }
    }
}
