<?php

namespace App\Assistant;

use App\Assistant\Tools\AnnouncementDraft;
use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * The conversation of one account, kept in its session only (never in the
 * database):
 *
 * - the transcript sent to the model, APPEND-ONLY: every user message and
 *   every model turn exactly as the API returned it (thinking blocks and
 *   their signatures included), never trimmed, summarized or rewritten;
 * - the plan's fingerprint and model it started with (App\Assistant\ConversationPlan);
 * - the questions and answers' text, for the page;
 * - the last proposed announcement draft.
 *
 * When it is full (turns or size), the page offers «Nueva conversación»
 * instead of cutting old messages. Signing out and switching accounts
 * invalidate the session (LogoutController, MicrosoftLoginController); it
 * is also stored with the account's id and ignored for any other account.
 */
final class Conversation
{
    public const KEY = 'assistant.conversation';

    public const DRAFT_KEY = 'assistant.draft';

    public function __construct(
        private readonly Session $session,
        private readonly User $user,
    ) {}

    /**
     * @return array{user_id: mixed, fingerprint: string, model: string, transcript: list<array<string, mixed>>, turns: list<array{question: string, answer: string}>}|null
     */
    private function state(): ?array
    {
        $stored = $this->session->get(self::KEY);

        return is_array($stored)
            && ($stored['user_id'] ?? null) === $this->user->getKey()
            && is_string($stored['fingerprint'] ?? null)
            && is_string($stored['model'] ?? null)
            && is_array($stored['transcript'] ?? null)
            && is_array($stored['turns'] ?? null)
            ? $stored
            : null;
    }

    public function exists(): bool
    {
        return $this->state() !== null;
    }

    public function fingerprint(): ?string
    {
        return $this->state()['fingerprint'] ?? null;
    }

    public function model(): ?string
    {
        return $this->state()['model'] ?? null;
    }

    /**
     * Starts an empty conversation frozen with this plan (the draft goes too).
     */
    public function begin(ConversationPlan $plan): void
    {
        $this->session->forget(self::DRAFT_KEY);
        $this->session->put(self::KEY, [
            'user_id' => $this->user->getKey(),
            'fingerprint' => $plan->fingerprint(),
            'model' => $plan->model,
            'transcript' => [],
            'turns' => [],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function transcript(): array
    {
        return array_values($this->state()['transcript'] ?? []);
    }

    /**
     * @return list<array{question: string, answer: string}>
     */
    public function turns(): array
    {
        return array_values(array_filter(
            $this->state()['turns'] ?? [],
            fn (mixed $turn) => is_array($turn) && is_string($turn['question'] ?? null) && is_string($turn['answer'] ?? null),
        ));
    }

    /**
     * Appends one question: its messages go at the end of the transcript,
     * nothing before them changes.
     *
     * @param  list<array<string, mixed>>  $messages
     */
    public function append(string $question, string $answer, array $messages): void
    {
        $state = $this->state();

        if ($state === null) {
            return;
        }

        $state['transcript'] = [...array_values($state['transcript']), ...$messages];
        $state['turns'] = [...array_values($state['turns']), ['question' => $question, 'answer' => $answer]];

        $this->session->put(self::KEY, $state);
    }

    /**
     * No room for another question: the history is never cut.
     */
    public function isFull(int $maxTurns, int $maxBytes): bool
    {
        $state = $this->state();

        return $state !== null
            && (count($state['turns']) >= $maxTurns || strlen((string) json_encode($state['transcript'])) >= $maxBytes);
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
