<?php

namespace App\Assistant;

/**
 * config/assistant.php read once, with safe values: a wrong effort is
 * `low`, numbers are at least 1, an empty fallback model means none.
 */
final readonly class AssistantSettings
{
    public const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    public function __construct(
        public string $model,
        public ?string $fallbackModel,
        public string $effort,
        public int $maxTokens,
        public int $dailyQuestions,
        public int $historyTurns,
        public int $questionMaxLength,
        public int $maxToolRounds,
    ) {}

    public static function fromConfig(): self
    {
        $effort = strtolower(trim((string) config('assistant.effort')));
        $model = trim((string) config('assistant.model')) ?: 'claude-opus-5-5';
        $fallback = trim((string) config('assistant.fallback_model'));

        return new self(
            model: $model,
            fallbackModel: $fallback === '' || $fallback === $model ? null : $fallback,
            effort: in_array($effort, self::EFFORTS, true) ? $effort : 'low',
            maxTokens: max(1024, (int) config('assistant.max_tokens')),
            dailyQuestions: max(1, (int) config('assistant.daily_questions')),
            historyTurns: max(1, (int) config('assistant.history_turns')),
            questionMaxLength: max(1, (int) config('assistant.question_max_length')),
            maxToolRounds: max(1, (int) config('assistant.max_tool_rounds')),
        );
    }
}
