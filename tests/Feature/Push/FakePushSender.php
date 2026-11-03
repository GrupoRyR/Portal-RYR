<?php

namespace Tests\Feature\Push;

use App\Push\PushMessage;
use App\Push\PushSender;
use RuntimeException;

/**
 * Records the notices instead of posting them; each endpoint answers
 * SENT unless {@see answer()} or `$everyone` says otherwise.
 * `$startable = false` makes start() fail (bad VAPID settings, no HTTP
 * client); `$throwOnSend` makes send() throw.
 */
final class FakePushSender implements PushSender
{
    /** @var list<PushMessage> */
    public array $sent = [];

    public bool $startable = true;

    public bool $throwOnSend = false;

    /** Every endpoint answers this, unless answer() says otherwise. */
    public ?string $everyone = null;

    /** @var array<string, string> outcome by endpoint */
    private array $answers = [];

    public function answer(string $endpoint, string $outcome): self
    {
        $this->answers[$endpoint] = $outcome;

        return $this;
    }

    public function start(): bool
    {
        return $this->startable;
    }

    public function send(array $messages): array
    {
        if ($this->throwOnSend) {
            throw new RuntimeException('The sender broke.');
        }

        $outcomes = [];

        foreach ($messages as $message) {
            $this->sent[] = $message;
            $outcomes[$message->key] = $this->answers[$message->endpoint] ?? $this->everyone ?? self::SENT;
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
