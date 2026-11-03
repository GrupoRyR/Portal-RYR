<?php

namespace App\Assistant\Model;

/**
 * What one model call sends, independent of the SDK.
 *
 * Messages are one of:
 * - `['role' => 'user'|'assistant', 'content' => string]`: plain text
 *   (the question, or a past turn from the session);
 * - `['role' => 'assistant', 'turn' => ModelTurn]`: a turn of this
 *   question, sent back verbatim (its tool calls, its thinking);
 * - `['role' => 'user', 'toolResults' => list<array{id: string, content: string, isError: bool}>]`.
 */
final readonly class ModelRequest
{
    /**
     * @param  list<string>  $system  Blocks of the system prompt: the first is stable (cached), the rest change per person or day.
     * @param  list<array{name: string, description: string, inputSchema: array<string, mixed>}>  $tools
     * @param  list<array<string, mixed>>  $messages
     */
    public function __construct(
        public string $model,
        public string $effort,
        public int $maxTokens,
        public array $system,
        public array $tools,
        public array $messages,
    ) {}
}
