<?php

namespace Tests\Feature\Console;

use App\Announcements\AnnouncementListEmails;
use App\Calendar\EventListEmails;
use App\Models\Company;
use App\Models\Event;
use App\Models\EventListEmail;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Events emailed to each company's all-staff list (odd/tasks/observaciones.md
 * O1): the same cron as announcements (portal:send-announcement-list-emails)
 * sends each published event that asks for it once per company, from the
 * company's own mailbox with a token of its own tenant, with Microsoft Graph.
 */
class SendEventListEmailsTest extends TestCase
{
    use RefreshDatabase;

    private const SEGUREX_TENANT = '11111111-1111-1111-1111-111111111111';

    private const DERIVADOS_TENANT = '22222222-2222-2222-2222-222222222222';

    private const SEGUREX_SEND_URL = 'https://graph.microsoft.com/v1.0/users/segurex.info@segurex.com/sendMail';

    private const DERIVADOS_SEND_URL = 'https://graph.microsoft.com/v1.0/users/derivados.info@derivadoscol.com/sendMail';

    private Company $segurex;

    private Company $derivados;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Mail::fake();
        config([
            'app.url' => 'https://apps.segurexlatam.net',
            'services.microsoft.client_id' => 'client-id',
            'services.microsoft.client_secret' => 'SECRET-VALUE-NEVER-SHOWN',
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00', 'America/Bogota'));

        $this->segurex = Company::factory()
            ->forTenant(self::SEGUREX_TENANT, ['segurex.com'])
            ->withListEmail('todos@segurex.com', 'segurex.info@segurex.com')
            ->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);
        $this->derivados = Company::factory()
            ->forTenant(self::DERIVADOS_TENANT, ['derivadoscol.com'])
            ->withListEmail('todos@derivadoscol.com', 'derivados.info@derivadoscol.com')
            ->create(['name' => 'Soluciones y Derivados', 'short_name' => 'Derivados']);
    }

    public function test_it_emails_each_company_list_from_its_own_mailbox(): void
    {
        $this->fakeMicrosoft();
        $this->queued(Event::factory()->create([
            'email_lists' => true,
            'title' => 'Pausa activa',
            'description' => "En la terraza.\nTraer ropa cómoda.",
            'location' => 'Sede norte',
            'starts_at' => '2026-11-10 15:00:00',
            'ends_at' => '2026-11-10 17:00:00',
        ]));

        $this->artisan('portal:send-announcement-list-emails')
            ->expectsOutputToContain('2 correos enviados')
            ->assertSuccessful();

        foreach ([
            [self::SEGUREX_SEND_URL, self::SEGUREX_TENANT, 'todos@segurex.com'],
            [self::DERIVADOS_SEND_URL, self::DERIVADOS_TENANT, 'todos@derivadoscol.com'],
        ] as [$url, $tenant, $list]) {
            Http::assertSent(function (Request $request) use ($url, $tenant, $list) {
                if ($request->url() !== $url) {
                    return false;
                }

                $content = (string) data_get($request->data(), 'message.body.content');

                return $request->hasHeader('Authorization', 'Bearer token-for-'.$tenant)
                    && data_get($request->data(), 'message.subject') === 'Pausa activa'
                    && data_get($request->data(), 'message.toRecipients') === [['emailAddress' => ['address' => $list]]]
                    && str_contains($content, 'Martes 10 de noviembre · 15:00 a 17:00')
                    && str_contains($content, 'Sede norte')
                    && str_contains($content, 'En la terraza.<br>Traer ropa cómoda.')
                    && str_contains($content, 'href="https://apps.segurexlatam.net/eventos"');
            });
        }

        Mail::assertNothingOutgoing();
        $this->assertSame([EventListEmail::STATUS_SENT], EventListEmail::query()->pluck('status')->unique()->values()->all());

        // Once each: a second run sends nothing.
        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();
        $this->assertSame(2, $this->sendRequests());
    }

    public function test_each_company_gets_only_the_events_addressed_to_it(): void
    {
        $this->fakeMicrosoft();
        $this->queued(Event::factory()->forCompanies($this->derivados)->create(['email_lists' => true]));

        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === self::DERIVADOS_SEND_URL);
        Http::assertNotSent(fn (Request $request) => $request->url() === self::SEGUREX_SEND_URL);
    }

    public function test_scheduled_withdrawn_and_unticked_events_wait_or_are_not_sent(): void
    {
        $this->fakeMicrosoft();
        $scheduled = $this->queued(Event::factory()->scheduled()->create(['email_lists' => true]));
        $this->queued(Event::factory()->withdrawn()->create(['email_lists' => true]));
        Event::factory()->create(['email_lists' => false]);

        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();
        $this->assertSame(0, $this->sendRequests());

        $this->travelTo($scheduled->published_at->copy()->addMinute());
        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();
        $this->assertSame(2, $this->sendRequests());
    }

    public function test_a_failure_is_recorded_and_the_other_company_still_gets_it(): void
    {
        $this->fakeMicrosoft([self::SEGUREX_SEND_URL => Http::response(['error' => ['code' => 'ErrorAccessDenied']], 403)]);
        $this->queued(Event::factory()->create(['email_lists' => true]));

        $this->artisan('portal:send-announcement-list-emails')
            ->expectsOutputToContain('1 correos enviados, 1 con error')
            ->assertSuccessful();

        $this->assertSame('ErrorAccessDenied', EventListEmail::query()->where('company_id', $this->segurex->id)->sole()->last_error);
        $this->assertSame(EventListEmail::STATUS_SENT, EventListEmail::query()->where('company_id', $this->derivados->id)->sole()->status);
    }

    public function test_the_text_is_escaped_never_sent_as_html_or_markdown(): void
    {
        $this->fakeMicrosoft();
        $this->queued(Event::factory()->forCompanies($this->segurex)->create([
            'email_lists' => true,
            'title' => "Fiesta <b>\n\n[aquí](https://evil.example)",
            'description' => "<script>alert(1)</script>\n\n![x](https://evil.example/x.png)",
            'location' => '<img src=x>',
        ]));

        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();

        Http::assertSent(function (Request $request) {
            $content = (string) data_get($request->data(), 'message.body.content');

            return $request->url() === self::SEGUREX_SEND_URL
                && ! str_contains($content, '<script>')
                && ! str_contains($content, '<b>')
                && ! str_contains($content, '<img src=x>')
                && ! str_contains($content, 'href="https://evil.example')
                && ! str_contains($content, 'src="https://evil.example');
        });
    }

    // --- Review fixes (odd/tasks/observaciones.md, R1) ---------------------------

    public function test_a_pending_email_of_an_event_that_is_over_is_never_sent(): void
    {
        $this->fakeMicrosoft();
        $this->queued(Event::factory()->past()->create(['email_lists' => true]));

        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();

        $this->assertSame(0, $this->sendRequests());
        $this->assertSame([EventListEmail::STATUS_PENDING], EventListEmail::query()->pluck('status')->unique()->values()->all());
    }

    public function test_a_row_left_sending_by_a_crash_is_marked_interrupted_and_never_resent_on_its_own(): void
    {
        $this->fakeMicrosoft();
        $event = $this->queued(Event::factory()->forCompanies($this->segurex)->create(['email_lists' => true]));
        $row = EventListEmail::query()->sole();
        $row->forceFill(['status' => EventListEmail::STATUS_SENDING, 'attempts' => 1])->save();
        EventListEmail::query()->whereKey($row->id)->toBase()->update(['updated_at' => now()->subMinutes(EventListEmails::STALE_MINUTES + 1)]);

        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();
        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();

        $row->refresh();
        $this->assertSame(EventListEmail::STATUS_FAILED, $row->status);
        $this->assertSame(EventListEmail::ERROR_INTERRUPTED, $row->last_error);
        $this->assertSame(0, $this->sendRequests());
        $this->assertTrue($event->exists);
    }

    public function test_a_recent_row_left_sending_is_not_touched_yet(): void
    {
        $this->fakeMicrosoft();
        $this->queued(Event::factory()->forCompanies($this->segurex)->create(['email_lists' => true]));
        EventListEmail::query()->update(['status' => EventListEmail::STATUS_SENDING]);

        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();

        $this->assertSame(EventListEmail::STATUS_SENDING, EventListEmail::query()->sole()->status);
        $this->assertSame(0, $this->sendRequests());
    }

    public function test_events_are_still_sent_when_the_announcement_batch_fails(): void
    {
        $this->fakeMicrosoft();
        $this->queued(Event::factory()->create(['email_lists' => true]));
        $this->app->instance(AnnouncementListEmails::class, new class extends AnnouncementListEmails
        {
            public function __construct() {}

            public function send(int $limit = self::BATCH): array
            {
                throw new \RuntimeException('database gone');
            }
        });

        $this->artisan('portal:send-announcement-list-emails')
            ->expectsOutputToContain('Anuncios: no se pudo enviar el lote')
            ->expectsOutputToContain('2 correos enviados')
            ->assertFailed();

        $this->assertSame(2, $this->sendRequests());
    }

    private function queued(Event $event): Event
    {
        app(EventListEmails::class)->queue($event);

        return $event;
    }

    private function fakeMicrosoft(array $send = []): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => fn (Request $request) => Http::response([
                'access_token' => 'token-for-'.explode('/', (string) parse_url($request->url(), PHP_URL_PATH))[1],
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/*' => fn (Request $request) => $send[$request->url()] ?? Http::response(null, 202),
        ]);
    }

    private function sendRequests(): int
    {
        return Http::recorded(fn (Request $request) => str_starts_with($request->url(), 'https://graph.microsoft.com/v1.0/users/'))->count();
    }
}
