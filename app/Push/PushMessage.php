<?php

namespace App\Push;

/**
 * One notice for one device. `key` identifies it in the sender's answer
 * (the delivery id); `payload` is the JSON the service worker reads
 * (resources/js/sw.js); `topic` lets the push service replace an older,
 * undelivered notice of the same announcement.
 */
final readonly class PushMessage
{
    public function __construct(
        public string $key,
        public string $endpoint,
        public string $publicKey,
        public string $authToken,
        public string $contentEncoding,
        public string $payload,
        public ?string $topic = null,
    ) {}
}
