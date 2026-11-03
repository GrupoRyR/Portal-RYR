<?php

namespace App\Push;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;
use Throwable;

/**
 * Sends notices with minishlink/web-push: each payload is encrypted for
 * its browser and signed with the VAPID pair (App\Push\PushSettings), one
 * request at a time, with short timeouts and no redirects
 * ({@see httpOptions()}). Only endpoints at the allowed push services are
 * ever called (App\Push\PushSubscriptions::allowsEndpoint()), so a stored
 * row cannot make the server post anywhere else. The library logs to the
 * app's log. A failure never stops the batch.
 */
class WebPushSender implements PushSender
{
    private ?WebPush $webPush = null;

    public function __construct(
        private readonly PushSettings $settings,
        private readonly ?ClientInterface $client = null,
    ) {}

    /**
     * Options of the production HTTP client.
     *
     * @return array{timeout: float, connect_timeout: float, allow_redirects: false, http_errors: false}
     */
    public static function httpOptions(): array
    {
        return [
            'timeout' => (float) config('push.timeout', 5),
            'connect_timeout' => (float) config('push.connect_timeout', 3),
            'allow_redirects' => false,
            'http_errors' => false,
        ];
    }

    public function start(): bool
    {
        if ($this->webPush !== null) {
            return true;
        }

        $vapid = $this->settings->vapid();

        if ($vapid === null) {
            return false;
        }

        try {
            // Refuses keys of the wrong length before any notice is claimed
            // (WebPush validates again; it takes the keys still encoded).
            VAPID::validate($vapid);
            $factory = new HttpFactory;

            $this->webPush = (new WebPush(
                ['VAPID' => $vapid],
                ['TTL' => (int) config('push.ttl', 43_200), 'urgency' => 'high'],
                $this->client ?? new Client(self::httpOptions()),
                $factory,
                $factory,
                null,
                app('log'),
            ))->setReuseVAPIDHeaders(true);
        } catch (Throwable $e) {
            // The message says what is wrong (a key's length), never the key.
            Log::error('Phone notices could not start; nothing was claimed.', ['exception' => $e::class, 'message' => $e->getMessage()]);

            return false;
        }

        return true;
    }

    public function send(array $messages): array
    {
        $outcomes = array_fill_keys(array_map(fn (PushMessage $message) => $message->key, $messages), self::FAILED);

        if ($messages === [] || ! $this->start()) {
            return $outcomes;
        }

        foreach ($messages as $message) {
            $outcomes[$message->key] = $this->sendOne($this->webPush, $message);
        }

        return $outcomes;
    }

    private function sendOne(WebPush $webPush, PushMessage $message): string
    {
        if (! PushSubscriptions::allowsEndpoint($message->endpoint)) {
            return self::FAILED;
        }

        try {
            $report = $webPush->sendOneNotification(
                new Subscription($message->endpoint, $message->publicKey, $message->authToken, $message->contentEncoding),
                $message->payload,
                array_filter(['topic' => $message->topic]),
            );
        } catch (Throwable $e) {
            Log::warning('Phone notice could not be sent.', ['key' => $message->key, 'exception' => $e::class]);

            return self::FAILED;
        }

        if ($report->isSuccess()) {
            return self::SENT;
        }

        $response = $report->getResponse();

        if ($response === null) {
            Log::warning('Phone notice got no answer.', ['key' => $message->key]);

            return self::UNREACHABLE;
        }

        if ($report->isSubscriptionExpired()) {
            return self::GONE;
        }

        // Never the endpoint: it is the device's secret address.
        Log::warning('Phone notice was refused.', ['key' => $message->key, 'status' => $response->getStatusCode()]);

        return self::FAILED;
    }
}
