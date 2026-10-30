<?php

namespace Tests\Feature\Console;

use App\Announcements\AnnouncementListEmails;
use App\Models\Announcement;
use App\Models\AnnouncementListEmail;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * portal:send-announcement-list-emails (odd/tasks/inicio-fase-2.md WU4):
 * every minute the cron emails each published announcement that asks for
 * it to the all-staff list of each company of its audience, once, from the
 * company's own mailbox with a token of the company's own tenant, always
 * with Microsoft Graph (never SMTP or the portal's notice mailer).
 */
class SendAnnouncementListEmailsCommandTest extends TestCase
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
        $this->travelTo(CarbonImmutable::parse('2026-10-30 10:00', 'America/Bogota'));

        $this->segurex = Company::factory()
            ->forTenant(self::SEGUREX_TENANT, ['segurex.com'])
            ->withListEmail('todos@segurex.com', 'segurex.info@segurex.com')
            ->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);
        $this->derivados = Company::factory()
            ->forTenant(self::DERIVADOS_TENANT, ['derivadoscol.com'])
            ->withListEmail('todos@derivadoscol.com', 'derivados.info@derivadoscol.com')
            ->create(['name' => 'Soluciones y Derivados', 'short_name' => 'Derivados']);
    }

    public function test_it_emails_each_company_list_from_its_own_mailbox_with_its_own_tenant_token(): void
    {
        $this->fakeMicrosoft();
        $announcement = $this->queued(Announcement::factory()->emailLists()->create([
            'title' => 'Cierre de oficinas el viernes',
            'body' => "Las oficinas cierran a las 2 p. m.\nGracias.",
        ]));

        $this->artisan('portal:send-announcement-list-emails')
            ->expectsOutputToContain('2 correos enviados')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://login.microsoftonline.com/'.self::SEGUREX_TENANT.'/oauth2/v2.0/token'
            && $request['client_id'] === 'client-id'
            && $request['scope'] === 'https://graph.microsoft.com/.default'
            && $request['grant_type'] === 'client_credentials');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://login.microsoftonline.com/'.self::DERIVADOS_TENANT.'/oauth2/v2.0/token');

        foreach ([
            [self::SEGUREX_SEND_URL, self::SEGUREX_TENANT, 'todos@segurex.com'],
            [self::DERIVADOS_SEND_URL, self::DERIVADOS_TENANT, 'todos@derivadoscol.com'],
        ] as [$url, $tenant, $list]) {
            Http::assertSent(function (Request $request) use ($url, $tenant, $list, $announcement) {
                if ($request->url() !== $url) {
                    return false;
                }

                $content = (string) data_get($request->data(), 'message.body.content');

                return $request->method() === 'POST'
                    && $request->hasHeader('Authorization', 'Bearer token-for-'.$tenant)
                    && $request['saveToSentItems'] === true
                    && data_get($request->data(), 'message.subject') === 'Cierre de oficinas el viernes'
                    && data_get($request->data(), 'message.toRecipients') === [['emailAddress' => ['address' => $list]]]
                    && data_get($request->data(), 'message.body.contentType') === 'HTML'
                    && ! array_key_exists('attachments', $request['message'])
                    && ! array_key_exists('from', $request['message'])
                    && str_contains($content, 'Cierre de oficinas el viernes')
                    && str_contains($content, 'Las oficinas cierran a las 2 p. m.<br>Gracias.')
                    && str_contains($content, 'href="https://apps.segurexlatam.net/anuncios/'.$announcement->id.'"')
                    && str_contains($content, 'Ver en el portal');
            });
        }

        $this->assertOnlyMicrosoftWasCalled();
        Http::assertSentCount(4);
        Mail::assertNothingOutgoing();

        $rows = AnnouncementListEmail::query()->orderBy('company_id')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(AnnouncementListEmail::STATUS_SENT, $row->status);
            $this->assertSame(1, $row->attempts);
            $this->assertNull($row->last_error);
            $this->assertTrue($row->sent_at->equalTo(now()));
        }
    }

    public function test_each_email_carries_the_brand_of_its_company(): void
    {
        $this->fakeMicrosoft();
        $this->segurex->update(['color_dark' => '#8F360B']);
        $this->derivados->update(['color_dark' => '#123456']);
        $this->queued(Announcement::factory()->emailLists()->create());

        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === self::SEGUREX_SEND_URL
            && str_contains((string) data_get($request->data(), 'message.body.content'), '#8F360B')
            && str_contains((string) data_get($request->data(), 'message.body.content'), 'Segurex Latam'));
        Http::assertSent(fn (Request $request) => $request->url() === self::DERIVADOS_SEND_URL
            && str_contains((string) data_get($request->data(), 'message.body.content'), '#123456')
            && str_contains((string) data_get($request->data(), 'message.body.content'), 'Soluciones y Derivados'));
    }

    public function test_it_sends_each_email_only_once(): void
    {
        $this->fakeMicrosoft();
        $announcement = $this->queued(Announcement::factory()->emailLists()->create());

        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();
        // Editing and saving it again with the box ticked does not send it again.
        $announcement->update(['title' => 'Título corregido', 'body' => 'Texto corregido']);
        $this->queued($announcement);
        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();

        $this->assertSame(2, $this->sendRequests());
        $this->assertSame(2, AnnouncementListEmail::query()->count());
    }

    public function test_a_scheduled_announcement_goes_out_when_its_start_arrives(): void
    {
        $this->fakeMicrosoft();
        $this->queued(Announcement::factory()->emailLists()->forCompanies($this->segurex)->create(['starts_at' => now()->addHour()]));

        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();
        $this->assertSame(0, $this->sendRequests());
        $this->assertSame(AnnouncementListEmail::STATUS_PENDING, AnnouncementListEmail::query()->sole()->status);

        $this->travel(61)->minutes();
        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();

        $this->assertSame(1, $this->sendRequests());
        Http::assertSent(fn (Request $request) => $request->url() === self::SEGUREX_SEND_URL);
    }

    public function test_withdrawn_expired_and_announcements_without_the_option_are_not_sent(): void
    {
        $this->fakeMicrosoft();
        $this->queued(Announcement::factory()->emailLists()->withdrawn()->create());
        $this->queued(Announcement::factory()->emailLists()->expired()->create());
        $this->queued(Announcement::factory()->create());

        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();

        Http::assertNothingSent();
        // Waiting, in case one is published again; none for the one without the option.
        $this->assertSame(4, AnnouncementListEmail::query()->where('status', AnnouncementListEmail::STATUS_PENDING)->count());
    }

    public function test_unticking_the_box_stops_the_emails_still_pending(): void
    {
        $this->fakeMicrosoft();
        $announcement = $this->queued(Announcement::factory()->emailLists()->create());
        $announcement->update(['email_lists' => false]);

        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_company_whose_list_is_configured_later_gets_nothing(): void
    {
        $this->fakeMicrosoft();
        $later = Company::factory()->forTenant('33333333-3333-3333-3333-333333333333', ['tercera.com'])->create(['short_name' => 'Tercera']);
        $this->queued(Announcement::factory()->emailLists()->create());
        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();

        $later->update(['all_staff_email' => 'todos@tercera.com', 'mail_sender' => 'info@tercera.com']);
        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();

        $this->assertSame(2, $this->sendRequests());
        $this->assertFalse(AnnouncementListEmail::query()->where('company_id', $later->id)->exists());
    }

    public function test_a_company_activated_later_gets_nothing(): void
    {
        $this->fakeMicrosoft();
        $this->derivados->update(['is_active' => false]);
        $this->queued(Announcement::factory()->emailLists()->create());
        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();

        $this->derivados->update(['is_active' => true]);
        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();

        $this->assertSame(1, $this->sendRequests());
        Http::assertNotSent(fn (Request $request) => $request->url() === self::DERIVADOS_SEND_URL);
    }

    public function test_a_pending_email_waits_while_its_company_is_inactive(): void
    {
        $this->fakeMicrosoft();
        $this->queued(Announcement::factory()->emailLists()->forCompanies($this->derivados)->create());
        $this->derivados->update(['is_active' => false]);

        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(AnnouncementListEmail::STATUS_PENDING, AnnouncementListEmail::query()->sole()->status);
    }

    public function test_a_title_with_line_breaks_never_becomes_markdown(): void
    {
        $this->fakeMicrosoft();
        // Written behind the editor's back (the editor refuses line breaks in the title).
        $this->queued(Announcement::factory()->emailLists()->forCompanies($this->segurex)->create([
            'title' => "Aviso\n\n[Entra aquí](https://evil.example/login) ![x](https://evil.example/t.png)\r\n\r\nFin",
        ]));

        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();

        Http::assertSent(function (Request $request) {
            $content = (string) data_get($request->data(), 'message.body.content');

            return $request->url() === self::SEGUREX_SEND_URL
                && data_get($request->data(), 'message.subject') === 'Aviso [Entra aquí](https://evil.example/login) ![x](https://evil.example/t.png) Fin'
                && ! str_contains($content, 'href="https://evil.example')
                && ! str_contains($content, 'src="https://evil.example')
                && str_contains($content, '[Entra aquí](https://evil.example/login)');
        });
    }

    public function test_only_active_companies_of_the_audience_with_both_fields_get_it(): void
    {
        $this->fakeMicrosoft();
        Company::factory()->inactive()->withListEmail('todos@inactiva.com', 'info@inactiva.com')->create(['domains' => ['inactiva.com']]);
        Company::factory()->create(['domains' => ['sinlista.com']]);
        $this->queued(Announcement::factory()->emailLists()->create());
        $this->queued(Announcement::factory()->emailLists()->forCompanies($this->derivados)->create());

        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();

        // The one for all: Segurex and Derivados; the one for Derivados: Derivados.
        $this->assertSame(3, $this->sendRequests());
        $this->assertSame([$this->segurex->id, $this->derivados->id, $this->derivados->id], AnnouncementListEmail::query()
            ->orderBy('announcement_id')->orderBy('company_id')->pluck('company_id')->all());
        $this->assertOnlyMicrosoftWasCalled();
    }

    public function test_a_failure_is_recorded_and_the_batch_goes_on(): void
    {
        Log::spy();
        $this->fakeMicrosoft([
            self::SEGUREX_SEND_URL => Http::response(['error' => ['code' => 'ErrorAccessDenied', 'message' => 'Access is denied. Check credentials and try again.']], 403),
        ]);
        $this->queued(Announcement::factory()->emailLists()->create());

        $this->artisan('portal:send-announcement-list-emails')
            ->expectsOutputToContain('1 correos enviados, 1 con error')
            ->assertSuccessful();

        $failed = AnnouncementListEmail::query()->where('company_id', $this->segurex->id)->sole();
        $this->assertSame(AnnouncementListEmail::STATUS_FAILED, $failed->status);
        $this->assertSame('ErrorAccessDenied', $failed->last_error);
        $this->assertNull($failed->sent_at);
        $this->assertSame(AnnouncementListEmail::STATUS_SENT, AnnouncementListEmail::query()->where('company_id', $this->derivados->id)->sole()->status);

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($failed) {
            $serialized = json_encode($context);

            return $message === 'Announcement list email could not be sent.'
                && $context['company_id'] === $this->segurex->id
                && $context['announcement_id'] === $failed->announcement_id
                && $context['error'] === 'ErrorAccessDenied'
                && ! str_contains($serialized, 'token-for-')
                && ! str_contains($serialized, 'SECRET-VALUE');
        });
    }

    public function test_a_refused_token_is_recorded_with_its_entra_code(): void
    {
        $this->fakeMicrosoft(token: Http::response(['error' => 'invalid_client', 'error_codes' => [7000215]], 401));
        $this->queued(Announcement::factory()->emailLists()->forCompanies($this->segurex)->create());

        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();

        $this->assertSame(0, $this->sendRequests());
        $row = AnnouncementListEmail::query()->sole();
        $this->assertSame(AnnouncementListEmail::STATUS_FAILED, $row->status);
        $this->assertSame('AADSTS7000215', $row->last_error);
    }

    public function test_a_mailbox_without_rest_access_and_an_unknown_answer_get_short_codes(): void
    {
        $this->fakeMicrosoft([
            self::SEGUREX_SEND_URL => Http::response(['error' => ['code' => 'MailboxNotEnabledForRESTAPI', 'message' => 'REST API is not yet supported for this mailbox.']], 404),
            self::DERIVADOS_SEND_URL => Http::response('<html>bad gateway</html>', 502),
        ]);
        $this->queued(Announcement::factory()->emailLists()->create());

        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();

        $this->assertSame('MailboxNotEnabledForRESTAPI', AnnouncementListEmail::query()->where('company_id', $this->segurex->id)->value('last_error'));
        $this->assertSame('HTTP 502', AnnouncementListEmail::query()->where('company_id', $this->derivados->id)->value('last_error'));
    }

    public function test_a_redirect_from_graph_is_not_followed(): void
    {
        $this->fakeMicrosoft([
            self::SEGUREX_SEND_URL => Http::response('', 302, ['Location' => 'https://elsewhere.example/steal']),
        ]);
        $this->queued(Announcement::factory()->emailLists()->forCompanies($this->segurex)->create());

        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();

        $this->assertSame('HTTP 302', AnnouncementListEmail::query()->value('last_error'));
        $this->assertOnlyMicrosoftWasCalled();
    }

    public function test_an_expired_token_is_renewed_once(): void
    {
        $answers = [Http::response(['error' => ['code' => 'InvalidAuthenticationToken']], 401), Http::response(null, 202)];
        $this->fakeMicrosoft([self::SEGUREX_SEND_URL => function () use (&$answers) {
            return array_shift($answers);
        }]);
        $this->queued(Announcement::factory()->emailLists()->forCompanies($this->segurex)->create());

        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();

        $this->assertSame(2, $this->sendRequests());
        $this->assertSame(AnnouncementListEmail::STATUS_SENT, AnnouncementListEmail::query()->value('status'));
    }

    public function test_the_text_is_escaped_never_sent_as_html_or_markdown(): void
    {
        $this->fakeMicrosoft();
        $this->queued(Announcement::factory()->emailLists()->forCompanies($this->segurex)->create([
            'title' => 'Aviso <b>importante</b>',
            'body' => "<script>alert(1)</script> [clic](javascript:alert(1))\n\n**negrita** <img src=x onerror=alert(1)>",
        ]));

        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();

        Http::assertSent(function (Request $request) {
            $content = (string) data_get($request->data(), 'message.body.content');

            return $request->url() === self::SEGUREX_SEND_URL
                && data_get($request->data(), 'message.subject') === 'Aviso <b>importante</b>'
                && str_contains($content, 'Aviso &lt;b&gt;importante&lt;/b&gt;')
                && str_contains($content, '&lt;script&gt;alert(1)&lt;/script&gt;')
                && str_contains($content, '[clic](javascript:alert(1))<br><br>**negrita** &lt;img src=x onerror=alert(1)&gt;')
                && ! str_contains($content, '<script')
                && ! str_contains($content, '<img src=x')
                && ! str_contains($content, 'href="javascript')
                && ! str_contains($content, '<strong>negrita');
        });
    }

    public function test_the_image_goes_as_an_absolute_https_link_and_never_as_an_attachment(): void
    {
        $this->fakeMicrosoft();
        $this->queued(Announcement::factory()->emailLists()->forCompanies($this->segurex)->create(['image_path' => 'announcements/1/foto.webp']));
        config(['filesystems.disks.public.url' => 'https://apps.segurexlatam.net/storage']);
        Storage::forgetDisk('public');

        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === self::SEGUREX_SEND_URL
            && str_contains((string) data_get($request->data(), 'message.body.content'), 'src="https://apps.segurexlatam.net/storage/announcements/1/foto.webp"')
            && ! array_key_exists('attachments', $request['message']));
    }

    public function test_an_image_without_an_https_address_is_left_out(): void
    {
        $this->fakeMicrosoft();
        $this->queued(Announcement::factory()->emailLists()->forCompanies($this->segurex)->create(['image_path' => 'announcements/1/foto.webp']));
        config(['filesystems.disks.public.url' => 'http://localhost/storage']);
        Storage::forgetDisk('public');

        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === self::SEGUREX_SEND_URL
            && ! str_contains((string) data_get($request->data(), 'message.body.content'), 'foto.webp'));
    }

    public function test_a_sender_or_list_outside_the_company_domains_is_refused_without_calling_graph(): void
    {
        $this->fakeMicrosoft();
        $this->queued(Announcement::factory()->emailLists()->forCompanies($this->segurex)->create());
        // Written behind the editor's back after it was queued: the cron checks again.
        $this->segurex->forceFill(['mail_sender' => 'derivados.info@derivadoscol.com'])->saveQuietly();

        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();

        Http::assertNothingSent();
        $row = AnnouncementListEmail::query()->sole();
        $this->assertSame(AnnouncementListEmail::STATUS_FAILED, $row->status);
        $this->assertSame('DomainMismatch', $row->last_error);
    }

    public function test_a_row_left_sending_by_a_crash_is_marked_interrupted_and_never_resent_on_its_own(): void
    {
        $this->fakeMicrosoft();
        $announcement = Announcement::factory()->emailLists()->forCompanies($this->segurex)->create();
        $row = AnnouncementListEmail::query()->create([
            'announcement_id' => $announcement->id,
            'company_id' => $this->segurex->id,
            'status' => AnnouncementListEmail::STATUS_SENDING,
            'attempts' => 1,
        ]);

        // Still within the time a run may take: left alone.
        $this->travel(AnnouncementListEmails::STALE_MINUTES - 1)->minutes();
        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();
        $this->assertSame(AnnouncementListEmail::STATUS_SENDING, $row->fresh()->status);

        $this->travel(2)->minutes();
        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();
        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();

        Http::assertNothingSent();
        $row->refresh();
        $this->assertSame(AnnouncementListEmail::STATUS_FAILED, $row->status);
        $this->assertSame(AnnouncementListEmail::ERROR_INTERRUPTED, $row->last_error);
        $this->assertSame(1, $row->attempts);
    }

    public function test_a_row_set_back_to_pending_is_sent_again(): void
    {
        $this->fakeMicrosoft();
        $announcement = Announcement::factory()->emailLists()->forCompanies($this->segurex)->create();
        $row = AnnouncementListEmail::query()->create([
            'announcement_id' => $announcement->id,
            'company_id' => $this->segurex->id,
            'status' => AnnouncementListEmail::STATUS_PENDING,
            'attempts' => 1,
            'last_error' => 'ErrorAccessDenied',
        ]);

        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();

        $row->refresh();
        $this->assertSame(AnnouncementListEmail::STATUS_SENT, $row->status);
        $this->assertSame(2, $row->attempts);
        $this->assertNull($row->last_error);
        $this->assertSame(1, $this->sendRequests());
    }

    public function test_a_pending_retry_waits_while_the_announcement_is_not_published(): void
    {
        $this->fakeMicrosoft();
        $announcement = Announcement::factory()->emailLists()->withdrawn()->forCompanies($this->segurex)->create();
        AnnouncementListEmail::query()->create([
            'announcement_id' => $announcement->id,
            'company_id' => $this->segurex->id,
            'status' => AnnouncementListEmail::STATUS_PENDING,
            'attempts' => 1,
        ]);

        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(AnnouncementListEmail::STATUS_PENDING, AnnouncementListEmail::query()->value('status'));
    }

    public function test_each_run_sends_a_bounded_batch(): void
    {
        $this->fakeMicrosoft();
        $count = intdiv(AnnouncementListEmails::BATCH, 2) + 1;
        Announcement::factory()->count($count)->emailLists()->create()->each(fn (Announcement $announcement) => $this->queued($announcement));

        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();
        $this->assertSame(AnnouncementListEmails::BATCH, $this->sendRequests());

        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();
        $this->assertSame($count * 2, $this->sendRequests());
        $this->assertSame($count * 2, AnnouncementListEmail::query()->where('status', AnnouncementListEmail::STATUS_SENT)->count());
    }

    public function test_without_anything_to_send_it_stays_quiet(): void
    {
        $this->fakeMicrosoft();

        $this->artisan('portal:send-announcement-list-emails')
            ->doesntExpectOutputToContain('correos')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_it_is_scheduled_every_minute_without_overlapping(): void
    {
        /** @var Event|null $event */
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'portal:send-announcement-list-emails'));

        $this->assertNotNull($event, 'portal:send-announcement-list-emails is not scheduled.');
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_a_killed_run_blocks_the_mail_commands_for_ten_minutes_at_most(): void
    {
        foreach (['portal:send-announcement-list-emails', 'portal:send-announcement-reminders'] as $command) {
            /** @var Event|null $event */
            $event = collect($this->app->make(Schedule::class)->events())
                ->first(fn (Event $event) => str_contains((string) $event->command, $command));

            $this->assertNotNull($event, "{$command} is not scheduled.");
            $this->assertTrue($event->withoutOverlapping, $command);
            $this->assertSame(10, $event->expiresAt, $command);
        }
    }

    /**
     * What saving the announcement with the box ticked does: a pending
     * email for each company that can get it now and has none yet.
     */
    private function queued(Announcement $announcement): Announcement
    {
        app(AnnouncementListEmails::class)->queue($announcement);

        return $announcement;
    }

    /**
     * Entra answers a token named after the tenant; Graph accepts every
     * sendMail unless $send gives another answer for its URL.
     *
     * @param  array<string, mixed>  $send
     */
    private function fakeMicrosoft(array $send = [], mixed $token = null): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => $token ?? fn (Request $request) => Http::response([
                'access_token' => 'token-for-'.explode('/', (string) parse_url($request->url(), PHP_URL_PATH))[1],
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/*' => function (Request $request) use ($send) {
                $answer = $send[$request->url()] ?? Http::response(null, 202);

                return is_callable($answer) ? $answer($request) : $answer;
            },
        ]);
    }

    private function sendRequests(): int
    {
        return Http::recorded(fn (Request $request) => str_starts_with($request->url(), 'https://graph.microsoft.com/v1.0/users/'))->count();
    }

    private function assertOnlyMicrosoftWasCalled(): void
    {
        $hosts = Http::recorded()->map(fn (array $pair) => parse_url($pair[0]->url(), PHP_URL_HOST))->unique()->sort()->values()->all();

        $this->assertSame(array_values(array_intersect(['graph.microsoft.com', 'login.microsoftonline.com'], $hosts)), $hosts);
    }
}
