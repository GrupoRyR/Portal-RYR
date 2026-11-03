<?php

namespace App\Push;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;
use Throwable;

/**
 * Sends notices with minishlink/web-push: each payload is encrypted for
 * its browser and signed with the VAPID pair (App\Push\PushSettings), one
 * request at a time, with short timeouts and no redirects. Only endpoints
 * at the allowed push services are ever called
 * (App\Push\PushSubscriptions::allowsEndpoint()), so a stored row cannot
 * make the server post anywhere else. A failure never stops the batch.
 */
class WebPushSender implements PushSender
{
    public function __construct(
        private readonly PushSettings $settings,
        private readonly ?ClientInterface $client = null,
    ) {}

    public function send(array $messages): array
    {
        $outcomes = array_fill_keys(array_map(fn (PushMessage $message) => $message->key, $messages), self::FAILED);
        $vapid = $this->settings->vapid();

        if ($vapid === null || $messages === []) {
            return $outcomes;
        }

        try {
            $webPush = $this->webPush($vapid);
        } catch (Throwable $e) {
            Log::warning('Phone notices could not start.', ['exception' => $e::class, 'message' => $e->getMessage()]);

            return $outcomes;
        }

        foreach ($messages as $message) {
            $outcomes[$message->key] = $this->sendOne($webPush, $message);
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

        if ($report->isSubscriptionExpired()) {
            return self::GONE;
        }

        // Never the endpoint: it is the device's secret address.
        Log::warning('Phone notice was refused.', [
            'key' => $message->key,
            'status' => $report->getResponse()?->getStatusCode(),
        ]);

        return self::FAILED;
    }

    /**
     * @param  array{subject: string, publicKey: string, privateKey: string}  $vapid
     */
    private function webPush(array $vapid): WebPush
    {
        $factory = new HttpFactory;

        $webPush = new WebPush(
            ['VAPID' => $vapid],
            ['TTL' => (int) config('push.ttl', 43_200), 'urgency' => 'high'],
            $this->client ?? new Client([
                'timeout' => (float) config('push.timeout', 5),
                'connect_timeout' => (float) config('push.connect_timeout', 3),
                'allow_redirects' => false,
                'http_errors' => false,
            ]),
            $factory,
            $factory,
        );

        return $webPush->setReuseVAPIDHeaders(true);
    }
}
