<?php

namespace App\Assistant;

use App\Assistant\Model\ModelRef;

/**
 * config/assistant.php read once, with safe values: a wrong effort is
 * `low`, numbers are at least 1, an empty editor model is the reader
 * model, and an empty fallback model (or one equal to the conversation's)
 * means none.
 */
final readonly class AssistantSettings
{
    public const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    public const DEFAULT_MODEL = 'anthropic:claude-haiku-5-5';

    public function __construct(
        public ModelRef $model,
        public ModelRef $editorModel,
        public ?ModelRef $fallbackModel,
        public string $effort,
        public int $maxTokensChat,
        public int $maxTokensDraft,
        public int $dailyQuestions,
        public int $conversationTurns,
        public int $conversationMaxBytes,
        public int $questionMaxLength,
        public int $maxToolRounds,
        public float $monthlyBudgetUsd,
        public ?ModelRef $secondaryModel = null,
        public ?ModelRef $secondaryEditorModel = null,
        public int $primaryCooldownMinutes = 30,
        public int $shortCooldownMinutes = 2,
        public int $deadlineSeconds = 90,
    ) {}

    public static function fromConfig(): self
    {
        $effort = strtolower(trim((string) config('assistant.effort')));
        $model = trim((string) config('assistant.model')) ?: self::DEFAULT_MODEL;
        $fallback = trim((string) config('assistant.fallback_model'));
        $secondary = trim((string) config('assistant.secondary_model'));
        $secondaryEditor = trim((string) config('assistant.secondary_editor_model'));

        return new self(
            model: ModelRef::parse($model),
            editorModel: ModelRef::parse(trim((string) config('assistant.editor_model')) ?: $model),
            fallbackModel: $fallback === '' ? null : ModelRef::parse($fallback),
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
            secondaryModel: $secondary === '' ? null : ModelRef::parse($secondary),
            secondaryEditorModel: ($secondaryEditor ?: $secondary) === '' ? null : ModelRef::parse($secondaryEditor ?: $secondary),
            primaryCooldownMinutes: max(1, (int) config('assistant.primary_cooldown_minutes')),
            shortCooldownMinutes: max(1, (int) config('assistant.short_cooldown_minutes')),
            deadlineSeconds: max(5, (int) config('assistant.deadline_seconds')),
        );
    }

    /**
     * The fallback for a conversation on $model, or null.
     */
    public function fallbackFor(ModelRef $model): ?ModelRef
    {
        return $this->fallbackModel === null || $this->fallbackModel->key() === $model->key() ? null : $this->fallbackModel;
    }

    /**
     * The failover model of the readers' or the editors' conversations, or null.
     */
    public function secondaryFor(bool $editor): ?ModelRef
    {
        return $editor ? $this->secondaryEditorModel : $this->secondaryModel;
    }

    /**
     * The models of every role (readers, editors, fallback), by role.
     *
     * @return array<string, ModelRef>
     */
    public function roles(): array
    {
        return array_filter([
            'reader' => $this->model,
            'editor' => $this->editorModel,
            'fallback' => $this->fallbackModel,
            'secondary' => $this->secondaryModel,
            'secondary_editor' => $this->secondaryEditorModel,
        ]);
    }
}
