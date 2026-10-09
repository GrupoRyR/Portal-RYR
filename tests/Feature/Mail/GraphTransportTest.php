<?php

namespace Tests\Feature\Mail;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class GraphTransportTest extends TestCase
{
    private const TENANT = '54f53029-e6e2-4b5f-a862-3454ae886468';

    private const CLIENT_ID = 'aaaabbbb-0000-cccc-1111-dddd2222eeee';

    private const SECRET = 'secreto~de~la~app';

    private const TOKEN = 'eyJ0eXAi.secret.token';

    private const TOKEN_URL = 'https://login.microsoftonline.com/'.self::TENANT.'/oauth2/v2.0/token';

    private const SENDER = 'segurex.info@segurex.com';

    private const SEND_URL = 'https://graph.microsoft.com/v1.0/users/'.self::SENDER.'/sendMail';

    private const CACHE_KEY = 'microsoft:mail-token:'.self::TENANT.':'.self::CLIENT_ID;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'graph',
            'mail.mailers.graph.tenant' => self::TENANT,
            'mail.mailers.graph.client_id' => self::CLIENT_ID,
            'mail.mailers.graph.client_secret' => self::SECRET,
            'mail.from.address' => self::SENDER,
            'mail.from.name' => 'Portal RYR',
        ]);
        Http::preventStrayRequests();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function fakeGraph(int $status = 202, array $body = []): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => self::TOKEN]),
            self::SEND_URL => Http::response($body, $status),
        ]);
    }

    private function sendSimple(string $to = 'ana@segurex.com'): void
    {
        Mail::raw('Hola', fn (Message $m) => $m->to($to)->subject('Prueba'));
    }

    /**
     * @return list<Request>
     */
    private function sent(string $url): array
    {
        return Http::recorded(fn (Request $request) => $request->url() === $url)
            ->map(fn (array $pair) => $pair[0])
            ->values()
            ->all();
    }

    private function assertNoSecretIn(string $text): void
    {
        $this->assertStringNotContainsString(self::SECRET, $text);
        $this->assertStringNotContainsString(self::TOKEN, $text);
        $this->assertStringNotContainsString('eyJ', $text);
    }

    public function test_a_message_is_sent_through_graph_send_mail(): void
    {
        $this->fakeGraph();

        Mail::html('<p>Hola <b>equipo</b></p>', function (Message $m) {
            $m->to('ana@segurex.com', 'Ana Pérez')
                ->cc('luis@segurex.com')
                ->bcc('oculto@segurex.com')
                ->replyTo('soporte@segurex.com', 'Soporte')
                ->subject('Empresa activada')
                ->attachData('hola', 'nota.txt', ['mime' => 'text/plain']);
        });

        $tokens = $this->sent(self::TOKEN_URL);
        $this->assertCount(1, $tokens);
        $this->assertSame('client_credentials', $tokens[0]['grant_type']);
        $this->assertSame(self::CLIENT_ID, $tokens[0]['client_id']);
        $this->assertSame(self::SECRET, $tokens[0]['client_secret']);
        $this->assertSame('https://graph.microsoft.com/.default', $tokens[0]['scope']);

        $sends = $this->sent(self::SEND_URL);
        $this->assertCount(1, $sends);
        $this->assertSame('POST', $sends[0]->method());
        $this->assertTrue($sends[0]->hasHeader('Authorization', 'Bearer '.self::TOKEN));

        $payload = $sends[0]->data();
        $this->assertFalse($payload['saveToSentItems']);
        $message = $payload['message'];
        $this->assertSame('Empresa activada', $message['subject']);
        $this->assertSame(['contentType' => 'HTML', 'content' => '<p>Hola <b>equipo</b></p>'], $message['body']);
        $this->assertSame(['emailAddress' => ['address' => self::SENDER, 'name' => 'Portal RYR']], $message['from']);
        $this->assertSame([['emailAddress' => ['address' => 'ana@segurex.com', 'name' => 'Ana Pérez']]], $message['toRecipients']);
        $this->assertSame([['emailAddress' => ['address' => 'luis@segurex.com']]], $message['ccRecipients']);
        $this->assertSame([['emailAddress' => ['address' => 'oculto@segurex.com']]], $message['bccRecipients']);
        $this->assertSame([['emailAddress' => ['address' => 'soporte@segurex.com', 'name' => 'Soporte']]], $message['replyTo']);
        $this->assertSame([[
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'name' => 'nota.txt',
            'contentType' => 'text/plain',
            'contentBytes' => base64_encode('hola'),
            'isInline' => false,
        ]], $message['attachments']);
    }

    public function test_a_text_only_message_uses_a_text_body(): void
    {
        $this->fakeGraph();

        $this->sendSimple();

        $message = $this->sent(self::SEND_URL)[0]->data()['message'];
        $this->assertSame(['contentType' => 'Text', 'content' => 'Hola'], $message['body']);
        $this->assertArrayNotHasKey('attachments', $message);
        $this->assertArrayNotHasKey('ccRecipients', $message);
        $this->assertArrayNotHasKey('bccRecipients', $message);
    }

    public function test_the_token_is_requested_once_for_several_messages(): void
    {
        $this->fakeGraph();

        $this->sendSimple('ana@segurex.com');
        $this->sendSimple('luis@segurex.com');
        $this->sendSimple('maria@segurex.com');

        $this->assertCount(1, $this->sent(self::TOKEN_URL));
        $this->assertCount(3, $this->sent(self::SEND_URL));
    }

    public function test_the_token_is_cached_under_a_key_without_secrets(): void
    {
        $this->fakeGraph();

        $this->sendSimple();

        $this->assertSame(self::TOKEN, Cache::get(self::CACHE_KEY));
        $this->assertNoSecretIn(self::CACHE_KEY);
    }

    public function test_a_graph_error_becomes_a_transport_exception_without_secrets(): void
    {
        $this->fakeGraph(403, ['error' => ['code' => 'ErrorAccessDenied', 'message' => 'Access is denied. Check credentials and try again.']]);

        try {
            $this->sendSimple();
            $this->fail('A Graph error must fail the send.');
        } catch (TransportException $e) {
            $this->assertStringContainsString('403', $e->getMessage());
            $this->assertStringContainsString('ErrorAccessDenied', $e->getMessage());
            $this->assertNoSecretIn($e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    public function test_a_401_from_graph_drops_the_cached_token(): void
    {
        $this->fakeGraph(401, ['error' => ['code' => 'InvalidAuthenticationToken', 'message' => 'Access token has expired.']]);

        try {
            $this->sendSimple();
            $this->fail('A 401 must fail the send.');
        } catch (TransportException $e) {
            $this->assertStringContainsString('InvalidAuthenticationToken', $e->getMessage());
        }

        $this->assertNull(Cache::get(self::CACHE_KEY));
    }

    public function test_a_token_error_becomes_a_transport_exception_without_secrets(): void
    {
        Http::fake([self::TOKEN_URL => Http::response([
            'error' => 'invalid_client',
            'error_description' => 'AADSTS7000215: Invalid client secret provided: '.self::SECRET,
            'error_codes' => [7000215],
        ], 401)]);

        try {
            $this->sendSimple();
            $this->fail('A token error must fail the send.');
        } catch (TransportException $e) {
            $this->assertStringContainsString('401', $e->getMessage());
            $this->assertStringContainsString('invalid_client', $e->getMessage());
            $this->assertStringContainsString('7000215', $e->getMessage());
            $this->assertNoSecretIn($e->getMessage());
        }

        $this->assertCount(0, $this->sent(self::SEND_URL));
    }

    public function test_an_unreachable_microsoft_becomes_a_transport_exception(): void
    {
        Http::fake([self::TOKEN_URL => fn () => throw new ConnectionException('cURL error 6 '.self::SECRET)]);

        try {
            $this->sendSimple();
            $this->fail('A connection error must fail the send.');
        } catch (TransportException $e) {
            $this->assertStringContainsString('could not be reached', $e->getMessage());
            $this->assertNoSecretIn($e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    public function test_attachments_over_three_megabytes_are_refused_before_calling_graph(): void
    {
        $this->fakeGraph();

        try {
            Mail::raw('Hola', fn (Message $m) => $m->to('ana@segurex.com')->subject('Grande')
                ->attachData(str_repeat('a', 3 * 1024 * 1024 + 1), 'grande.bin'));
            $this->fail('Oversized attachments must be refused.');
        } catch (TransportException $e) {
            $this->assertStringContainsString('3 MB', $e->getMessage());
        }

        $this->assertCount(0, $this->sent(self::SEND_URL));
    }

    public function test_a_missing_configuration_is_reported_clearly(): void
    {
        config(['mail.mailers.graph.tenant' => null]);
        $this->fakeGraph();

        try {
            $this->sendSimple();
            $this->fail('A missing tenant must fail the send.');
        } catch (TransportException $e) {
            $this->assertStringContainsString('MICROSOFT_MAIL_TENANT_ID', $e->getMessage());
        }

        Http::assertNothingSent();
    }
}
