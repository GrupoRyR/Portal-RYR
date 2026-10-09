<?php

namespace Tests\Feature\Mail;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MailTestCommandTest extends TestCase
{
    public function test_it_sends_a_test_message_to_the_given_address(): void
    {
        config(['mail.default' => 'array']);

        $this->artisan('portal:mail-test', ['to' => 'ana@segurex.com'])
            ->expectsOutputToContain('ana@segurex.com')
            ->assertSuccessful();

        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $email = $sent->first()->getOriginalMessage();
        $this->assertSame('ana@segurex.com', $email->getTo()[0]->getAddress());
        $this->assertSame('Prueba de correo del Portal RYR', $email->getSubject());
    }

    public function test_an_invalid_address_is_rejected_without_sending(): void
    {
        config(['mail.default' => 'array']);

        $this->artisan('portal:mail-test', ['to' => 'no-es-correo'])
            ->expectsOutputToContain('no es una dirección de correo válida')
            ->assertFailed();

        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_a_failure_is_printed_and_the_command_fails(): void
    {
        config([
            'mail.default' => 'graph',
            'mail.mailers.graph.tenant' => '54f53029-e6e2-4b5f-a862-3454ae886468',
            'mail.mailers.graph.client_id' => 'aaaabbbb-0000-cccc-1111-dddd2222eeee',
            'mail.mailers.graph.client_secret' => 'secreto',
            'mail.from.address' => 'segurex.info@segurex.com',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => 'tok']),
            'graph.microsoft.com/*' => Http::response(['error' => ['code' => 'ErrorAccessDenied', 'message' => 'Access is denied.']], 403),
        ]);

        $this->artisan('portal:mail-test', ['to' => 'ana@segurex.com'])
            ->expectsOutputToContain('ErrorAccessDenied')
            ->assertFailed();
    }
}
