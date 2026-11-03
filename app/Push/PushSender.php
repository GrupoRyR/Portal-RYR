<?php

namespace App\Push;

/**
 * Posts notices to the browsers' push services. App\Push\WebPushSender in
 * the app; the tests bind a fake (no network).
 */
interface PushSender
{
    public const SENT = 'sent';

    /** The subscription no longer exists (404/410): delete it. */
    public const GONE = 'gone';

    /** The push service answered with an error, or the notice could not be built. */
    public const FAILED = 'failed';

    /** No HTTP answer at all (DNS, connection, TLS, timeout). */
    public const UNREACHABLE = 'unreachable';

    /**
     * Gets ready to send (HTTP client, VAPID settings the library accepts),
     * before anything is claimed. False, already logged, when it cannot.
     */
    public function start(): bool;

    /**
     * @param  list<PushMessage>  $messages
     * @return array<string, string> SENT, GONE, FAILED or UNREACHABLE by message key
     */
    public function send(array $messages): array;
}
