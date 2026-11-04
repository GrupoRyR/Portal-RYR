<?php

namespace App\Assistant\Model;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Throwable;

/**
 * The `openai_compatible` driver: Chat Completions (`POST {base_url}/chat/completions`)
 * over Laravel's HTTP client, for providers such as DeepSeek. HTTPS only,
 * with a timeout and one retry on 429, 5xx and connection errors.
 *
 * The transcript stays in the portal's (Anthropic wire) shape and is
 * translated here, both ways:
 * - the system prompt is the first `system` message; user text blocks are
 *   one `user` message; `tool_result` blocks are `tool` messages;
 * - assistant `text` and `tool_use` blocks are `content` and `tool_calls`
 *   (thinking blocks, which only Anthropic writes, are not sent);
 * - the answer comes back as `text` and `tool_use` blocks; a reasoning
 *   text of the provider (`reasoning_content`) is not kept.
 *
 * Nothing Anthropic-only is ever sent: no cache_control, no adaptive
 * thinking, no effort. `finish_reason` maps to the portal's stop reasons
 * (`content_filter` is a refusal), and usage to the same fields:
 * `prompt_tokens` is input and `completion_tokens` output; with DeepSeek's
 * `prompt_cache_hit_tokens` / `prompt_cache_miss_tokens` (when present)
 * the hits are cache reads and the misses input. Verify these field names
 * at api-docs.deepseek.com before relying on them.
 */
class OpenAiCompatibleModel implements AssistantModel
{
    public const CONNECT_TIMEOUT = 10;

    private const STOP_REASONS = [
        'stop' => 'end_turn',
        'tool_calls' => ModelTurn::TOOL_USE,
        'function_call' => ModelTurn::TOOL_USE,
        'length' => ModelTurn::MAX_TOKENS,
        'content_filter' => ModelTurn::REFUSAL,
    ];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeout,
    ) {}

    public function send(ModelRequest $request): ModelTurn
    {
        if (trim((string) $this->apiKey) === '' || ! self::isHttps($this->baseUrl)) {
            throw new ModelUnavailable('not_configured');
        }

        try {
            $response = $this->http
                ->baseUrl(rtrim($this->baseUrl, '/'))
                ->withToken((string) $this->apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout(min($this->timeout, $request->timeout ?? $this->timeout))
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->withOptions(['allow_redirects' => false])
                ->retry(2, 500, fn (Throwable $e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && ($e->response->status() === 429 || $e->response->serverError())), throw: false)
                ->post('/chat/completions', self::body($request));
        } catch (ConnectionException $e) {
            throw new ModelUnavailable(Str::contains(strtolower($e->getMessage()), ['timed out', 'timeout']) ? 'timeout' : 'connection', $e);
        }

        if (! $response->successful()) {
            throw new ModelUnavailable(self::reason($response));
        }

        return self::turn($response, $request->model);
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(ModelRequest $request): array
    {
        $body = [
            'model' => $request->model,
            'max_tokens' => $request->maxTokens,
            'messages' => [['role' => 'system', 'content' => $request->system], ...self::messages($request->messages)],
        ];

        if ($request->tools !== []) {
            $body['tools'] = array_map(fn (array $tool) => ['type' => 'function', 'function' => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'parameters' => $tool['inputSchema'],
            ]], $request->tools);
        }

        return $body;
    }

    /**
     * @param  list<array{role: string, content: list<array<string, mixed>>}>  $messages
     * @return list<array<string, mixed>>
     */
    private static function messages(array $messages): array
    {
        $out = [];

        foreach ($messages as $message) {
            $text = [];
            $calls = [];

            foreach ($message['content'] as $block) {
                match ($block['type'] ?? null) {
                    'text' => $text[] = (string) $block['text'],
                    'tool_use' => $calls[] = ['id' => $block['id'], 'type' => 'function', 'function' => [
                        'name' => $block['name'],
                        'arguments' => is_string($block[ModelTurn::INPUT_JSON] ?? null)
                            ? $block[ModelTurn::INPUT_JSON]
                            : json_encode($block['input'] === [] ? new \stdClass : $block['input'], JSON_UNESCAPED_UNICODE),
                    ]],
                    'tool_result' => $out[] = ['role' => 'tool', 'tool_call_id' => $block['tool_use_id'], 'content' => (string) $block['content']],
                    default => null, // thinking and other Anthropic-only blocks
                };
            }

            if ($message['role'] === 'assistant') {
                if ($text !== [] || $calls !== []) {
                    $out[] = ['role' => 'assistant', 'content' => $text === [] ? null : implode("\n\n", $text), ...($calls === [] ? [] : ['tool_calls' => $calls])];
                }
            } elseif ($text !== []) {
                $out[] = ['role' => 'user', 'content' => implode("\n\n", $text)];
            }
        }

        return $out;
    }

    private static function turn(Response $response, string $requested): ModelTurn
    {
        $choice = $response->json('choices.0') ?? [];
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $text = trim((string) ($message['content'] ?? ''));
        $content = $text === '' ? [] : [['type' => 'text', 'text' => $text]];
        $calls = [];

        foreach (is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [] as $call) {
            $arguments = json_decode((string) ($call['function']['arguments'] ?? ''), true);
            $input = is_array($arguments) ? $arguments : [];
            $id = (string) ($call['id'] ?? Str::random(12));
            $name = (string) ($call['function']['name'] ?? '');

            $calls[] = new ToolCall($id, $name, $input);
            $content[] = ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input, ModelTurn::INPUT_JSON => is_array($arguments) ? (string) $call['function']['arguments'] : '{}'];
        }

        $usage = $response->json('usage') ?? [];
        $hits = $usage['prompt_cache_hit_tokens'] ?? null;
        $misses = $usage['prompt_cache_miss_tokens'] ?? null;
        $cached = is_int($hits) && is_int($misses);

        return new ModelTurn(
            stopReason: self::STOP_REASONS[$choice['finish_reason'] ?? 'stop'] ?? 'end_turn',
            text: $text,
            toolCalls: $calls,
            inputTokens: $cached ? $misses : (int) ($usage['prompt_tokens'] ?? 0),
            outputTokens: (int) ($usage['completion_tokens'] ?? 0),
            model: (string) ($response->json('model') ?: $requested),
            content: $content,
            cacheReadInputTokens: $cached ? $hits : 0,
        );
    }

    /**
     * A short code for Auditoría from the status, never the provider's message.
     */
    private static function reason(Response $response): string
    {
        $status = $response->status();

        return match (true) {
            $status === 401, $status === 403 => 'auth',
            $status === 402 => 'billing',
            $status === 429 => 'rate_limited',
            $status === 503, $status === 529 => 'overloaded',
            default => 'http_'.$status,
        };
    }

    private static function isHttps(string $url): bool
    {
        return str_starts_with(strtolower($url), 'https://') && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
