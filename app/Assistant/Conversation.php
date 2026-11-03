<?php

namespace App\Assistant;

use App\Assistant\Tools\AnnouncementDraft;
use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * The conversation of one account, kept in its session only (never in the
 * database): the questions and the answers' text, the newest
 * {@see AssistantSettings::$historyTurns} of them, and the last proposed
 * announcement draft.
 *
 * Signing out and switching accounts invalidate the session
 * (LogoutController, MicrosoftLoginController), so it goes with it. It is
 * also stored with the account's id and ignored for any other account.
 */
final class Conversation
{
    public const KEY = 'assistant.conversation';

    public const DRAFT_KEY = 'assistant.draft';

    public function __construct(
        private readonly Session $session,
        private readonly User $user,
        private readonly int $maxTurns,
    ) {}

    /**
     * @return list<array{question: string, answer: string}>
     */
    public function turns(): array
    {
        $stored = $this->session->get(self::KEY);

        if (! is_array($stored) || ($stored['user_id'] ?? null) !== $this->user->getKey() || ! is_array($stored['turns'] ?? null)) {
            return [];
        }

        return array_values(array_filter(
            $stored['turns'],
            fn (mixed $turn) => is_array($turn) && is_string($turn['question'] ?? null) && is_string($turn['answer'] ?? null),
        ));
    }

    /**
     * The past turns as model messages: plain text only (no tool results,
     * no thinking), so nothing of an earlier question is replayed.
     *
     * @return list<array{role: string, content: string}>
     */
    public function messages(): array
    {
        $messages = [];

        foreach ($this->turns() as $turn) {
            $messages[] = ['role' => 'user', 'content' => $turn['question']];
            $messages[] = ['role' => 'assistant', 'content' => $turn['answer']];
        }

        return $messages;
    }

    public function add(string $question, string $answer): void
    {
        $turns = [...$this->turns(), ['question' => $question, 'answer' => $answer]];

        $this->session->put(self::KEY, [
            'user_id' => $this->user->getKey(),
            'turns' => array_slice($turns, -$this->maxTurns),
        ]);
    }

    public function draft(): ?AnnouncementDraft
    {
        $stored = $this->session->get(self::DRAFT_KEY);

        return is_array($stored) && ($stored['user_id'] ?? null) === $this->user->getKey()
            ? AnnouncementDraft::fromArray($stored)
            : null;
    }

    public function keepDraft(AnnouncementDraft $draft): void
    {
        $this->session->put(self::DRAFT_KEY, ['user_id' => $this->user->getKey(), ...$draft->toArray()]);
    }

    public function forgetDraft(): void
    {
        $this->session->forget(self::DRAFT_KEY);
    }

    public function clear(): void
    {
        $this->session->forget([self::KEY, self::DRAFT_KEY]);
    }
}
