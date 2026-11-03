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

    public const FAILED = 'failed';

    /**
     * @param  list<PushMessage>  $messages
     * @return array<string, string> SENT, GONE or FAILED by message key
     */
    public function send(array $messages): array;
}
