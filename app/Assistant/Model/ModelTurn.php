<?php

namespace App\Assistant\Model;

/**
 * One answer of the model: why it stopped (`end_turn`, `tool_use`,
 * `refusal`, `max_tokens`...), its text, the tools it asked for, the
 * tokens it used and, opaque, its content blocks to send back verbatim on
 * the next call of the same question.
 */
final readonly class ModelTurn
{
    public const REFUSAL = 'refusal';

    public const TOOL_USE = 'tool_use';

    /**
     * @param  list<ToolCall>  $toolCalls
     */
    public function __construct(
        public string $stopReason,
        public string $text,
        public array $toolCalls,
        public int $inputTokens,
        public int $outputTokens,
        public string $model,
        public mixed $content = null,
    ) {}
}
