<?php

namespace App\Assistant\Model;

/**
 * What one model call sends, independent of the SDK. The prompt prefix
 * (tools, then the system prompt) is the same for the whole conversation
 * (App\Assistant\ConversationPlan), so it is cached.
 *
 * Messages are in the API's own (wire) shape, so they can be kept in the
 * session and sent back byte for byte: `['role' => 'user'|'assistant',
 * 'content' => list<block>]`, with `text`, `tool_result`
 * (`tool_use_id`, `content`, `is_error`) and, from the model, its own
 * blocks (`thinking` with its `signature`, `tool_use`, `text`...).
 */
final readonly class ModelRequest
{
    /**
     * @param  list<array{name: string, description: string, inputSchema: array<string, mixed>, strict?: bool}>  $tools
     * @param  list<array{role: string, content: list<array<string, mixed>>}>  $messages
     */
    public function __construct(
        public string $model,
        public string $effort,
        public int $maxTokens,
        public string $system,
        public array $tools,
        public array $messages,
        // The provider of `assistant.providers` that serves $model.
        public string $provider = ModelRef::DEFAULT_PROVIDER,
        // Seconds this call may take at most (the question's deadline); null: the driver's own.
        public ?int $timeout = null,
    ) {}
}
