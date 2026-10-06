<?php

namespace Tests\Unit\Microsoft;

use App\Microsoft\ConsentChecker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConsentCheckerTest extends TestCase
{
    private const TENANT = '54f53029-e6e2-4b5f-a862-3454ae886468';

    private const SECRET = 'secreto~de~la~app';

    private const TOKEN_URL = 'https://login.microsoftonline.com/'.self::TENANT.'/oauth2/v2.0/token';

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.microsoft.client_id' => 'aaaabbbb-0000-cccc-1111-dddd2222eeee',
            'services.microsoft.client_secret' => self::SECRET,
        ]);
        Http::preventStrayRequests();
        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = $event;
        });
    }

    private function error(int $code): array
    {
        return [
            'error' => 'unauthorized_client',
            'error_description' => "AADSTS{$code}: Something happened.\r\nTrace ID: x",
            'error_codes' => [$code],
        ];
    }

    private function assertNothingSecretWasLogged(): void
    {
        foreach ($this->logged as $event) {
            $line = $event->message.json_encode($event->context);
            $this->assertStringNotContainsString(self::SECRET, $line);
            $this->assertStringNotContainsString('eyJ', $line);
        }
    }

    public function test_a_token_means_consent_was_granted(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => 'eyJ0eXAi.secret.token'])]);

        $this->assertSame(ConsentChecker::GRANTED, app(ConsentChecker::class)->check(self::TENANT));

        Http::assertSent(fn (Request $request) => $request->url() === self::TOKEN_URL
            && $request['grant_type'] === 'client_credentials'
            && $request['scope'] === 'https://graph.microsoft.com/.default'
            && $request['client_id'] === 'aaaabbbb-0000-cccc-1111-dddd2222eeee'
            && $request['client_secret'] === self::SECRET);
        $this->assertNothingSecretWasLogged();
    }

    /**
     * Codes that mean the app is not installed (no admin consent) in that
     * tenant: https://learn.microsoft.com/en-us/entra/identity-platform/reference-error-codes
     *
     * @return array<string, array{int}>
     */
    public static function pendingCodes(): array
    {
        return [
            'AADSTS700016 application not found in the tenant' => [700016],
            'AADSTS65001 no consent' => [65001],
            'AADSTS500011 resource principal not found' => [500011],
        ];
    }

    #[DataProvider('pendingCodes')]
    public function test_missing_consent_errors_mean_pending(int $code): void
    {
        Http::fake([self::TOKEN_URL => Http::response($this->error($code), 400)]);

        $this->assertSame(ConsentChecker::PENDING, app(ConsentChecker::class)->check(self::TENANT));
        $this->assertNothingSecretWasLogged();
    }

    public function test_the_code_is_read_from_the_description_when_error_codes_is_missing(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['error' => 'unauthorized_client', 'error_description' => 'AADSTS700016: Application not found.'], 400)]);

        $this->assertSame(ConsentChecker::PENDING, app(ConsentChecker::class)->check(self::TENANT));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function unknownCodes(): array
    {
        return [
            'AADSTS7000215 invalid secret' => [7000215],
            'AADSTS7000222 expired secret' => [7000222],
            'AADSTS90002 tenant not found' => [90002],
            'AADSTS53003 conditional access' => [53003],
        ];
    }

    #[DataProvider('unknownCodes')]
    public function test_other_errors_are_unknown(int $code): void
    {
        Http::fake([self::TOKEN_URL => Http::response($this->error($code), 401)]);

        $this->assertSame(ConsentChecker::UNKNOWN, app(ConsentChecker::class)->check(self::TENANT));
        $this->assertCount(1, array_filter($this->logged, fn (MessageLogged $event) => $event->level === 'warning'));
        $this->assertNothingSecretWasLogged();
    }

    public function test_server_errors_and_odd_bodies_are_unknown(): void
    {
        Http::fake([self::TOKEN_URL => Http::sequence()
            ->push('Service unavailable', 503)
            ->push(['token_type' => 'Bearer'], 200)]);

        $this->assertSame(ConsentChecker::UNKNOWN, app(ConsentChecker::class)->check(self::TENANT));
        $this->assertSame(ConsentChecker::UNKNOWN, app(ConsentChecker::class)->check(self::TENANT));
    }

    public function test_a_network_error_is_unknown(): void
    {
        Http::fake([self::TOKEN_URL => fn () => throw new ConnectionException('cURL error 28: timed out')]);

        $this->assertSame(ConsentChecker::UNKNOWN, app(ConsentChecker::class)->check(self::TENANT));
        $this->assertNothingSecretWasLogged();
    }

    public function test_a_missing_secret_is_unknown_without_calling_microsoft(): void
    {
        config(['services.microsoft.client_secret' => null]);
        Http::fake();

        $this->assertSame(ConsentChecker::UNKNOWN, app(ConsentChecker::class)->check(self::TENANT));
        Http::assertNothingSent();
        $this->assertNothingSecretWasLogged();
    }

    public function test_an_invalid_tenant_is_unknown_without_calling_microsoft(): void
    {
        Http::fake();

        $this->assertSame(ConsentChecker::UNKNOWN, app(ConsentChecker::class)->check('../common'));
        Http::assertNothingSent();
    }

    public function test_the_admin_consent_link_targets_the_tenant(): void
    {
        config(['services.microsoft.redirect' => 'https://portal.example.com/auth/microsoft/callback']);

        $this->assertSame(
            'https://login.microsoftonline.com/'.self::TENANT.'/adminconsent?client_id=aaaabbbb-0000-cccc-1111-dddd2222eeee&redirect_uri=https%3A%2F%2Fportal.example.com%2Fauth%2Fmicrosoft%2Fcallback&state=admin-consent',
            app(ConsentChecker::class)->adminConsentUrl(self::TENANT),
        );
    }
}
