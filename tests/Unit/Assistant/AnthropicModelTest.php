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
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * The SDK implementation of the assistant's model, against a mocked HTTP
 * client (Guzzle's MockHandler): what goes on the wire and how the answer
 * is read back. No request ever leaves the machine.
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
    private function request(array $messages = [['role' => 'user', 'content' => '¿Qué tareas tengo?']]): ModelRequest
    {
        return new ModelRequest(
            model: 'claude-opus-5-5',
            effort: 'low',
            maxTokens: 16000,
            system: ['Instrucciones estables.', 'Hoy es martes.'],
            tools: [[
                'name' => 'mis_tareas',
                'description' => 'Las tareas pendientes.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
            ]],
            messages: $messages,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $content
     */
    private static function answer(array $content, string $stopReason, array $usage = ['input_tokens' => 100, 'output_tokens' => 20]): Response
    {
        return new Response(200, ['Content-Type' => 'application/json', 'request-id' => 'req_1'], json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5-5',
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

    public function test_it_sends_the_configured_request_and_reads_tool_calls_and_usage(): void
    {
        $turn = $this->model([self::answer([
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-1'],
            ['type' => 'text', 'text' => 'Voy a mirar.'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'mis_tareas', 'input' => (object) [], 'caller' => ['type' => 'direct']],
        ], 'tool_use', ['input_tokens' => 100, 'output_tokens' => 20, 'cache_read_input_tokens' => 50, 'cache_creation_input_tokens' => 5])])
            ->send($this->request());

        $this->assertSame(ModelTurn::TOOL_USE, $turn->stopReason);
        $this->assertSame('Voy a mirar.', $turn->text);
        $this->assertCount(1, $turn->toolCalls);
        $this->assertSame(['toolu_1', 'mis_tareas', []], [$turn->toolCalls[0]->id, $turn->toolCalls[0]->name, $turn->toolCalls[0]->input]);
        $this->assertSame(155, $turn->inputTokens);
        $this->assertSame(20, $turn->outputTokens);
        $this->assertSame('claude-opus-5-5', $turn->model);

        $request = $this->sent[0]['request'];
        $this->assertSame('https://api.anthropic.com/v1/messages', (string) $request->getUri());
        // The SDK sends the API key as a bearer token.
        $this->assertSame('Bearer sk-ant-test', $request->getHeaderLine('Authorization'));

        $body = $this->body(0);
        $this->assertSame('claude-opus-5-5', $body['model']);
        $this->assertSame(16000, $body['max_tokens']);
        $this->assertSame(['effort' => 'low'], $body['output_config']);
        $this->assertSame(['type' => 'adaptive'], $body['thinking']);
        $this->assertSame([
            ['type' => 'text', 'text' => 'Instrucciones estables.', 'cache_control' => ['type' => 'ephemeral']],
            ['type' => 'text', 'text' => 'Hoy es martes.'],
        ], $body['system']);
        $this->assertSame([['role' => 'user', 'content' => '¿Qué tareas tengo?']], $body['messages']);
        $this->assertSame('mis_tareas', $body['tools'][0]['name']);
        $this->assertArrayNotHasKey('temperature', $body);
        // An empty schema must stay a JSON object, not a list.
        $this->assertStringContainsString('"properties":{}', (string) $request->getBody());
    }

    public function test_the_next_call_sends_the_turn_back_verbatim_with_the_tool_results(): void
    {
        $model = $this->model([
            self::answer([
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-1'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'mis_tareas', 'input' => (object) [], 'caller' => ['type' => 'direct']],
            ], 'tool_use'),
            self::answer([['type' => 'text', 'text' => 'Tienes una tarea.']], 'end_turn'),
        ]);

        $first = $model->send($this->request());
        $second = $model->send($this->request([
            ['role' => 'user', 'content' => '¿Qué tareas tengo?'],
            ['role' => 'assistant', 'turn' => $first],
            ['role' => 'user', 'toolResults' => [['id' => 'toolu_1', 'content' => '{"tareas":[]}', 'isError' => false]]],
        ]));

        $this->assertSame('end_turn', $second->stopReason);
        $this->assertSame('Tienes una tarea.', $second->text);
        $this->assertSame([], $second->toolCalls);

        $messages = $this->body(1)['messages'];
        $this->assertSame('assistant', $messages[1]['role']);
        $this->assertEquals(['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-1'], $messages[1]['content'][0]);
        $this->assertSame('tool_use', $messages[1]['content'][1]['type']);
        $this->assertSame('toolu_1', $messages[1]['content'][1]['id']);
        $this->assertSame([
            'role' => 'user',
            'content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => '{"tareas":[]}', 'is_error' => false]],
        ], $messages[2]);
    }

    public function test_a_refusal_comes_back_as_its_stop_reason(): void
    {
        $turn = $this->model([self::answer([], 'refusal')])->send($this->request());

        $this->assertSame(ModelTurn::REFUSAL, $turn->stopReason);
        $this->assertSame('', $turn->text);
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
