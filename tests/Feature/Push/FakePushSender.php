<?php

namespace Tests\Feature\Push;

use App\Push\PushMessage;
use App\Push\PushSender;

/**
 * Records the notices instead of posting them; each endpoint answers
 * SENT unless {@see answer()} says otherwise.
 */
final class FakePushSender implements PushSender
{
    /** @var list<PushMessage> */
    public array $sent = [];

    /** @var array<string, string> outcome by endpoint */
    private array $answers = [];

    public function answer(string $endpoint, string $outcome): self
    {
        $this->answers[$endpoint] = $outcome;

        return $this;
    }

    public function send(array $messages): array
    {
        $outcomes = [];

        foreach ($messages as $message) {
            $this->sent[] = $message;
            $outcomes[$message->key] = $this->answers[$message->endpoint] ?? self::SENT;
        }

        return $outcomes;
    }

    /**
     * @return list<string>
     */
    public function endpoints(): array
    {
        return array_map(fn (PushMessage $message) => $message->endpoint, $this->sent);
    }
}
