<?php

namespace App\Assistant;

use App\Assistant\Tools\AnnouncementDraft;

/**
 * One model's tool loop inside a question (App\Assistant\Assistant): how
 * it ended, the text for the page, and the messages to keep after the
 * question (complete turns only).
 */
final class Run
{
    public const ANSWERED = 'answered';

    public const REFUSED = 'refused';

    public const TRUNCATED = 'truncated';

    public const TOO_MANY = 'too_many';

    public const FAILED = 'failed';

    public string $status = self::ANSWERED;

    public string $text = '';

    public ?string $error = null;

    /** @var list<array<string, mixed>> */
    public array $kept = [];

    /** @var list<string> Tool calls, in order. */
    public array $tools = [];

    public ?AnnouncementDraft $draft = null;

    public function __construct(public string $model, public string $provider = 'anthropic') {}

    /**
     * @param  list<array<string, mixed>>  $last  Messages kept after the ones so far.
     */
    public function ends(string $status, string $text, array $last = [], ?string $error = null): self
    {
        $this->status = $status;
        $this->text = $text;
        $this->error = $error;
        $this->kept = [...$this->kept, ...$last];

        return $this;
    }

    /**
     * This fallback run, with the tool calls and draft of the run that
     * was refused before it (for the audit and the page).
     */
    public function asFallback(self $refused): self
    {
        $this->tools = [...$refused->tools, ...$this->tools];
        $this->draft ??= $refused->draft;

        return $this;
    }
}
