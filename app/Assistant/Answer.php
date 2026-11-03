<?php

namespace App\Assistant;

use App\Assistant\Tools\AnnouncementDraft;

/**
 * The outcome of one question: the text for the page, the draft the model
 * proposed (if any), and what Auditoría records about it (never the text).
 */
final readonly class Answer
{
    public const FEATURE_CHAT = 'chat';

    public const FEATURE_DRAFT = 'draft';

    /**
     * @param  list<string>  $tools  Tools called, each once, in the order first called.
     * @param  string|null  $error  ModelUnavailable's reason when the model could not answer.
     */
    public function __construct(
        public string $text,
        public string $model,
        public Usage $usage = new Usage,
        public string $provider = 'anthropic',
        public array $tools = [],
        public int $toolCalls = 0,
        public bool $fallback = false,
        public bool $refused = false,
        public bool $truncated = false,
        public ?string $error = null,
        public ?AnnouncementDraft $draft = null,
    ) {}

    public function failed(): bool
    {
        return $this->error !== null;
    }

    /** `draft` when the question proposed an announcement draft, `chat` otherwise. */
    public function feature(): string
    {
        return $this->draft === null ? self::FEATURE_CHAT : self::FEATURE_DRAFT;
    }
}
