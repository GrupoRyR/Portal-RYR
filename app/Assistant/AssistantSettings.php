<?php

namespace App\Assistant;

/**
 * config/assistant.php read once, with safe values: a wrong effort is
 * `low`, numbers are at least 1, an empty editor model is the reader
 * model, and an empty fallback model (or one equal to the conversation's)
 * means none.
 */
final readonly class AssistantSettings
{
    public const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    public const DEFAULT_MODEL = 'claude-haiku-5-5';

    public function __construct(
        public string $model,
        public string $editorModel,
        public ?string $fallbackModel,
        public string $effort,
        public int $maxTokensChat,
        public int $maxTokensDraft,
        public int $dailyQuestions,
        public int $conversationTurns,
        public int $conversationMaxBytes,
        public int $questionMaxLength,
        public int $maxToolRounds,
        public float $monthlyBudgetUsd,
    ) {}

    public static function fromConfig(): self
    {
        $effort = strtolower(trim((string) config('assistant.effort')));
        $model = trim((string) config('assistant.model')) ?: self::DEFAULT_MODEL;
        $fallback = trim((string) config('assistant.fallback_model'));

        return new self(
            model: $model,
            editorModel: trim((string) config('assistant.editor_model')) ?: $model,
            fallbackModel: $fallback === '' ? null : $fallback,
            effort: in_array($effort, self::EFFORTS, true) ? $effort : 'low',
            // Thinking counts in max_tokens and cannot be turned off: never tiny.
            maxTokensChat: max(1024, (int) config('assistant.max_tokens.chat')),
            maxTokensDraft: max(1024, (int) config('assistant.max_tokens.draft')),
            dailyQuestions: max(1, (int) config('assistant.daily_questions')),
            conversationTurns: max(1, (int) config('assistant.conversation_turns')),
            conversationMaxBytes: max(16, (int) config('assistant.conversation_max_kb')) * 1024,
            questionMaxLength: max(1, (int) config('assistant.question_max_length')),
            maxToolRounds: max(1, (int) config('assistant.max_tool_rounds')),
            monthlyBudgetUsd: max(0.0, (float) config('assistant.monthly_budget_usd')),
        );
    }

    /**
     * The fallback for a conversation on $model, or null.
     */
    public function fallbackFor(string $model): ?string
    {
        return $this->fallbackModel === null || $this->fallbackModel === $model ? null : $this->fallbackModel;
    }
}
