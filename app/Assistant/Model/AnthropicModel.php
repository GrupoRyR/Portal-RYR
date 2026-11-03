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

/**
 * The assistant's model through the official PHP SDK (`anthropic-ai/sdk`,
 * Messages API, non-streaming): adaptive thinking with the configured
 * effort, the stable system block cached, the portal's tools, and every
 * turn of the question sent back verbatim (thinking blocks included).
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

        try {
            $message = $this->client()->messages->create(
                maxTokens: $request->maxTokens,
                messages: array_map(self::message(...), $request->messages),
                model: $request->model,
                outputConfig: ['effort' => $request->effort],
                system: self::system($request->system),
                // On Opus 5.5 omitting it is the same; the fallback model may need it said.
                thinking: ['type' => 'adaptive'],
                tools: $request->tools === [] ? null : array_map(fn (array $tool) => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'inputSchema' => $tool['inputSchema'],
                ], $request->tools),
            );
        } catch (AnthropicException $e) {
            throw new ModelUnavailable(self::reason($e), $e);
        }

        return self::turn($message, $request->model);
    }

    private function client(): Client
    {
        return new Client(
            apiKey: (string) $this->apiKey,
            requestOptions: [
                'transporter' => $this->transporter ?? new GuzzleClient([
                    'timeout' => $this->timeout,
                    'connect_timeout' => self::CONNECT_TIMEOUT,
                ]),
                'maxRetries' => self::MAX_RETRIES,
            ],
        );
    }

    /**
     * @param  list<string>  $blocks
     * @return list<array<string, mixed>>
     */
    private static function system(array $blocks): array
    {
        $system = [];

        foreach (array_values($blocks) as $index => $text) {
            $block = ['type' => 'text', 'text' => $text];

            // The stable instructions: the prefix (tools + this block) is cached.
            if ($index === 0) {
                $block['cacheControl'] = ['type' => 'ephemeral'];
            }

            $system[] = $block;
        }

        return $system;
    }

    /**
     * App\Assistant\Model\ModelRequest's messages in the SDK's shape.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private static function message(array $message): array
    {
        if (isset($message['turn']) && $message['turn'] instanceof ModelTurn) {
            return ['role' => 'assistant', 'content' => $message['turn']->content];
        }

        if (isset($message['toolResults'])) {
            return ['role' => 'user', 'content' => array_map(fn (array $result) => [
                'type' => 'tool_result',
                'toolUseID' => $result['id'],
                'content' => $result['content'],
                'isError' => $result['isError'],
            ], $message['toolResults'])];
        }

        return ['role' => $message['role'], 'content' => (string) $message['content']];
    }

    private static function turn(Message $message, string $requested): ModelTurn
    {
        $text = [];
        $calls = [];

        foreach ($message->content as $block) {
            if ($block instanceof ToolUseBlock) {
                $calls[] = new ToolCall($block->id, $block->name, $block->input);
            } elseif ($block->type === 'text') {
                $text[] = $block->text;
            }
        }

        $usage = $message->usage;

        return new ModelTurn(
            stopReason: (string) ($message->stopReason ?? 'end_turn'),
            text: trim(implode("\n\n", $text)),
            toolCalls: $calls,
            inputTokens: $usage->inputTokens + ($usage->cacheReadInputTokens ?? 0) + ($usage->cacheCreationInputTokens ?? 0),
            outputTokens: $usage->outputTokens,
            model: $message->model ?: $requested,
            content: $message->content,
        );
    }

    /**
     * A short code for Auditoría, never the API's message.
     */
    private static function reason(AnthropicException $e): string
    {
        return match (true) {
            $e instanceof APITimeoutException => 'timeout',
            $e instanceof APIConnectionException => 'connection',
            $e instanceof RateLimitException => 'rate_limited',
            $e instanceof AuthenticationException, $e instanceof PermissionDeniedException => 'auth',
            $e instanceof APIStatusException && $e->status === 529 => 'overloaded',
            $e instanceof APIStatusException && $e->status !== null => 'http_'.$e->status,
            default => 'unexpected',
        };
    }
}
