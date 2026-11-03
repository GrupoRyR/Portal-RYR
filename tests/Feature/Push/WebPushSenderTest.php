<?php

namespace Tests\Feature\Push;

use App\Push\PushMessage;
use App\Push\PushSender;
use App\Push\PushSettings;
use App\Push\WebPushSender;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * The real sender (minishlink/web-push) against a mocked HTTP client: it
 * encrypts and signs each notice, and turns the push service's answer
 * into SENT, GONE (404/410) or FAILED. No network.
 */
class WebPushSenderTest extends TestCase
{
    use PushTestKeys;

    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    private function sender(Response ...$responses): WebPushSender
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new WebPushSender(app(PushSettings::class), new Client(['handler' => $stack]));
    }

    private function message(string $key, string $endpoint): PushMessage
    {
        return new PushMessage($key, $endpoint, self::P256DH, self::AUTH, 'aes128gcm', '{"title":"Corte de luz","topic":"x"}', 'anuncio-7');
    }

    public function test_it_signs_encrypts_and_reads_the_answers(): void
    {
        $this->requireEcKeys();
        $this->configurePush();

        $outcomes = $this->sender(new Response(201), new Response(410), new Response(404), new Response(500))->send([
            $this->message('1', 'https://fcm.googleapis.com/fcm/send/ok'),
            $this->message('2', 'https://fcm.googleapis.com/fcm/send/expired'),
            $this->message('3', 'https://web.push.apple.com/missing'),
            $this->message('4', 'https://updates.push.services.mozilla.com/wpush/v2/error'),
        ]);

        $this->assertSame(['1' => PushSender::SENT, '2' => PushSender::GONE, '3' => PushSender::GONE, '4' => PushSender::FAILED], $outcomes);
        $this->assertCount(4, $this->history);

        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://fcm.googleapis.com/fcm/send/ok', (string) $request->getUri());
        $this->assertStringStartsWith('vapid t=', $request->getHeaderLine('Authorization'));
        $this->assertStringContainsString('k='.self::VAPID_PUBLIC, $request->getHeaderLine('Authorization'));
        $this->assertSame('aes128gcm', $request->getHeaderLine('Content-Encoding'));
        $this->assertSame((string) config('push.ttl'), $request->getHeaderLine('TTL'));
        $this->assertSame('high', $request->getHeaderLine('Urgency'));
        $this->assertSame('anuncio-7', $request->getHeaderLine('Topic'));
        // Encrypted for the browser: the text never travels in the clear.
        $this->assertStringNotContainsString('Corte de luz', (string) $request->getBody());
    }

    public function test_it_never_posts_to_an_unknown_host_or_without_settings(): void
    {
        $this->requireEcKeys();
        $this->configurePush();

        $this->assertSame(['1' => PushSender::FAILED], $this->sender()->send([$this->message('1', 'https://intranet.example.com/x')]));
        $this->assertSame([], $this->history);

        config(['push.vapid.private_key' => null]);
        $this->assertSame(['1' => PushSender::FAILED], $this->sender()->send([$this->message('1', 'https://fcm.googleapis.com/fcm/send/ok')]));
        $this->assertSame([], $this->history);
    }
}
