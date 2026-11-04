<?php

namespace App\Assistant\Model;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Messages\Message;
use Anthropic\Messages\ToolUseBlock;
use GuzzleHttp\Client as GuzzleClient;
use Psr\Http\Client\ClientInterface;
use stdClass;

/**
 * The assistant's model through the official PHP SDK (`anthropic-ai/sdk`,
 * Messages API, non-streaming), the same request shape on every model the
 * portal uses (Haiku 5.5, Sonnet 5.5, Opus 5.5, the fallback): adaptive
 * thinking with the configured effort, never `budget_tokens`, a disabled
 * thinking, sampling parameters, a forced `tool_choice` or an assistant
 * prefill (each a 400 on some of them).
 *
 * Prompt caching: the render order is tools, then system, then messages.
 * The single, static system block carries a breakpoint (so tools + system
 * are cached), and the last block of the latest user message carries a
 * moving one (so the earlier history is read from the cache). Moving a
 * breakpoint does not count as editing the history.
 *
 * The messages are the transcript's wire-shaped blocks, sent back as they
 * came (thinking signatures included); only the PHP SDK's key names and
 * the `{}` of an empty tool input, which the session's JSON turns into
 * `[]`, are restored here.
 *
 * The SDK leaves the timeout to the HTTP client, so the client is a Guzzle
 * one with `assistant.timeout` seconds; one retry on the errors the SDK
 * retries (429, 5xx, connection). Tests pass their own PSR-18 client and
 * never reach the network.
 */
class AnthropicModel implements AssistantModel
{
    public const CONNECT_TIMEOUT = 10;

    public const MAX_RETRIES = 1;

    /** Wire keys of message blocks the PHP SDK spells differently. */
    private const PARAM_KEYS = [
        'tool_use_id' => 'toolUseID',
        'is_error' => 'isError',
        'cache_control' => 'cacheControl',
    ];

    public function __construct(
        private readonly ?string $apiKey,
        private readonly int $timeout,
        private readonly ?ClientInterface $transporter = null,
    ) {}

    public function send(ModelRequest $request): ModelTurn
    {
        if (trim((string) $this->apiKey) === '') {
            throw new ModelUnavailable('not_configured');
        }

        $transport = new CapturingTransport($this->transporter ?? new GuzzleClient([
            'timeout' => min($this->timeout, $request->timeout ?? $this->timeout),
            'connect_timeout' => self::CONNECT_TIMEOUT,
        ]));

        try {
            $message = $this->client($transport)->messages->create(
                maxTokens: $request->maxTokens,
                messages: self::messages($request->messages),
                model: $request->model,
                outputConfig: ['effort' => $request->effort],
                system: [['type' => 'text', 'text' => $request->system, 'cacheControl' => ['type' => 'ephemeral']]],
                // Omitting it is the same on the 5.5 models; the fallback may need it said.
                thinking: ['type' => 'adaptive'],
                tools: $request->tools === [] ? null : array_map(fn (array $tool) => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'inputSchema' => $tool['inputSchema'],
                    ...(($tool['strict'] ?? false) === true ? ['strict' => true] : []),
                ], $request->tools),
            );
        } catch (AnthropicException $e) {
            throw new ModelUnavailable(self::reason($e), $e);
        }

        return self::turn($message, $request->model, $transport->lastJson());
    }

    private function client(ClientInterface $transport): Client
    {
        return new Client(
            apiKey: (string) $this->apiKey,
            requestOptions: [
                'transporter' => $transport,
                'maxRetries' => self::MAX_RETRIES,
            ],
        );
    }

    /**
     * The transcript in the SDK's shape, with the moving cache breakpoint
     * on the last block of the latest user message.
     *
     * @param  list<array{role: string, content: list<array<string, mixed>>}>  $messages
     * @return list<array<string, mixed>>
     */
    private static function messages(array $messages): array
    {
        $latestUser = null;

        foreach ($messages as $index => $message) {
            if ($message['role'] === 'user' && $message['content'] !== []) {
                $latestUser = $index;
            }
        }

        $params = [];

        foreach ($messages as $index => $message) {
            $blocks = array_map(self::block(...), $message['content']);

            if ($index === $latestUser) {
                $blocks[array_key_last($blocks)]['cacheControl'] = ['type' => 'ephemeral'];
            }

            $params[] = ['role' => $message['role'], 'content' => $blocks];
        }

        return $params;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private static function block(array $block): array
    {
        $param = [];

        foreach ($block as $key => $value) {
            if ($value === null || $key === ModelTurn::INPUT_JSON) {
                continue;
            }

            $param[self::PARAM_KEYS[$key] ?? $key] = $value;
        }

        if (($param['type'] ?? null) === 'tool_use') {
            // The input exactly as the API wrote it: the session's JSON turns
            // every {} (nested too) into [], which would edit the turn.
            $raw = is_string($block[ModelTurn::INPUT_JSON] ?? null) ? json_decode($block[ModelTurn::INPUT_JSON], false) : null;
            $param['input'] = is_object($raw) ? $raw : (($param['input'] ?? []) === [] ? new stdClass : $param['input']);
        }

        return $param;
    }

    private static function turn(Message $message, string $requested, ?object $raw = null): ModelTurn
    {
        $rawBlocks = is_array($raw->content ?? null) ? $raw->content : [];

        $text = [];
        $calls = [];
        $content = [];

        foreach (array_values($message->content) as $index => $block) {
            if ($block instanceof ToolUseBlock) {
                $calls[] = new ToolCall($block->id, $block->name, $block->input);
            } elseif ($block->type === 'text') {
                $text[] = $block->text;
            }

            // The wire shape, lossless (an empty thinking text and its signature too).
            $wire = json_decode(json_encode($block, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

            if ($block instanceof ToolUseBlock && isset($rawBlocks[$index]->input)) {
                $wire[ModelTurn::INPUT_JSON] = json_encode($rawBlocks[$index]->input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            $content[] = $wire;
        }

        $usage = $message->usage;

        return new ModelTurn(
            stopReason: (string) ($message->stopReason ?? 'end_turn'),
            text: trim(implode("\n\n", $text)),
            toolCalls: $calls,
            inputTokens: $usage->inputTokens,
            outputTokens: $usage->outputTokens,
            model: $message->model ?: $requested,
            content: $content,
            cacheCreationInputTokens: (int) ($usage->cacheCreationInputTokens ?? 0),
            cacheReadInputTokens: (int) ($usage->cacheReadInputTokens ?? 0),
        );
    }

    /**
     * A short code for Auditoría, never the API's message.
     */
    private static function reason(AnthropicException $e): string
    {
        // By the API's error TYPE (the SDK's ->type), then the status; never the text.
        $type = $e instanceof APIStatusException ? $e->type?->value : null;

        return match (true) {
            $e instanceof APITimeoutException => 'timeout',
            $e instanceof APIConnectionException => 'connection',
            $type === 'billing_error', $e instanceof APIStatusException && $e->status === 402 => 'billing',
            $type === 'authentication_error', $type === 'permission_error', $e instanceof AuthenticationException, $e instanceof PermissionDeniedException => 'auth',
            $type === 'rate_limit_error', $e instanceof RateLimitException => 'rate_limited',
            $type === 'overloaded_error', $e instanceof APIStatusException && $e->status === 529 => 'overloaded',
            $type === 'timeout_error' => 'timeout',
            $e instanceof APIStatusException && $e->status !== null => 'http_'.$e->status,
            default => 'unexpected',
        };
    }
}
