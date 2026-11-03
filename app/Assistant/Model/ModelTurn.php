<?php

namespace App\Assistant\Model;

/**
 * One answer of the model: why it stopped (`end_turn`, `tool_use`,
 * `refusal`, `max_tokens`...), its text, the tools it asked for, the
 * tokens it used (uncached input, cache writes, cache reads, output) and
 * its content blocks in the API's wire shape, to keep and send back
 * unchanged (thinking signatures included).
 */
final readonly class ModelTurn
{
    public const REFUSAL = 'refusal';

    public const TOOL_USE = 'tool_use';

    public const MAX_TOKENS = 'max_tokens';

    /**
     * @param  list<ToolCall>  $toolCalls
     * @param  list<array<string, mixed>>  $content
     */
    public function __construct(
        public string $stopReason,
        public string $text,
        public array $toolCalls,
        public int $inputTokens,
        public int $outputTokens,
        public string $model,
        public array $content = [],
        public int $cacheCreationInputTokens = 0,
        public int $cacheReadInputTokens = 0,
    ) {}

    /**
     * The turn as a message of the transcript.
     *
     * @return array{role: string, content: list<array<string, mixed>>}
     */
    public function message(): array
    {
        return ['role' => 'assistant', 'content' => $this->content];
    }
}
