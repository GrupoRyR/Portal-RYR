<?php

namespace Tests\Feature\Console;

use App\Calendar\ColombianHolidays;
use App\Digest\WeeklyDigestBuilder;
use App\Digest\WeeklyDigestContent;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\DirectoryEntry;
use App\Models\Event;
use App\Models\User;
use App\Models\WeeklyDigest;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * portal:send-weekly-digest (odd/tasks/inicio-fase-2.md WU3): every
 * Monday morning (Bogotá) each active company with «Resumen de los lunes»
 * on and a valid list gets one email, from its own mailbox with a token
 * of its own tenant, always with Microsoft Graph: the announcements of the
 * last seven days and the mandatory ones still in force, the dates of the
 * week (holidays, events, yearly dates), the birthdays and work
 * anniversaries of the week, and a link to the portal. Only what every
 * person of that company sees in the portal. Never twice a week.
 */
class SendWeeklyDigestCommandTest extends TestCase
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
        // Monday 9 November 2026: no holiday that week.
        $this->travelTo(CarbonImmutable::parse('2026-11-09 07:00', 'America/Bogota'));

        $this->segurex = Company::factory()
            ->forTenant(self::SEGUREX_TENANT, ['segurex.com'])
            ->withListEmail('todos@segurex.com', 'segurex.info@segurex.com')
            ->weeklyDigest()
            ->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);
        $this->derivados = Company::factory()
            ->forTenant(self::DERIVADOS_TENANT, ['derivadoscol.com'])
            ->withListEmail('todos@derivadoscol.com', 'derivados.info@derivadoscol.com')
            ->weeklyDigest()
            ->create(['name' => 'Soluciones y Derivados', 'short_name' => 'Derivados']);
    }

    public function test_it_emails_each_company_list_from_its_own_mailbox_with_its_own_tenant_token(): void
    {
        $this->fakeMicrosoft();
        Announcement::factory()->create(['title' => 'Cierre de oficinas el viernes', 'body' => 'TEXTO-DEL-CUERPO', 'starts_at' => now()->subDays(2)]);

        $this->artisan('portal:send-weekly-digest')
            ->expectsOutputToContain('2 enviados')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://login.microsoftonline.com/'.self::SEGUREX_TENANT.'/oauth2/v2.0/token'
            && $request['grant_type'] === 'client_credentials');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://login.microsoftonline.com/'.self::DERIVADOS_TENANT.'/oauth2/v2.0/token');

        foreach ([
            [self::SEGUREX_SEND_URL, self::SEGUREX_TENANT, 'todos@segurex.com'],
            [self::DERIVADOS_SEND_URL, self::DERIVADOS_TENANT, 'todos@derivadoscol.com'],
        ] as [$url, $tenant, $list]) {
            Http::assertSent(function (Request $request) use ($url, $tenant, $list) {
                if ($request->url() !== $url) {
                    return false;
                }

                $content = (string) data_get($request->data(), 'message.body.content');

                return $request->method() === 'POST'
                    && $request->hasHeader('Authorization', 'Bearer token-for-'.$tenant)
                    && $request['saveToSentItems'] === true
                    && data_get($request->data(), 'message.subject') === 'Resumen de la semana del 9 al 15 de noviembre'
                    && data_get($request->data(), 'message.toRecipients') === [['emailAddress' => ['address' => $list]]]
                    && data_get($request->data(), 'message.body.contentType') === 'HTML'
                    && ! array_key_exists('from', $request['message'])
                    && str_contains($content, 'Cierre de oficinas el viernes')
                    // Titles only: the body stays in the portal.
                    && ! str_contains($content, 'TEXTO-DEL-CUERPO')
                    && str_contains($content, 'href="https://apps.segurexlatam.net"');
            });
        }

        $this->assertOnlyMicrosoftWasCalled();
        $this->assertSame(2, $this->sendRequests());
        Mail::assertNothingOutgoing();

        $rows = WeeklyDigest::query()->orderBy('company_id')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(WeeklyDigest::STATUS_SENT, $row->status);
            $this->assertSame('2026-11-09', $row->week_start->toDateString());
            $this->assertNull($row->error_code);
            $this->assertTrue($row->sent_at->equalTo(now()));
        }
    }

    public function test_each_email_carries_the_brand_of_its_company(): void
    {
        $this->fakeMicrosoft();
        $this->segurex->update(['color_dark' => '#8F360B']);
        $this->derivados->update(['color_dark' => '#123456']);
        Announcement::factory()->create();

        $this->artisan('portal:send-weekly-digest')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === self::SEGUREX_SEND_URL
            && str_contains((string) data_get($request->data(), 'message.body.content'), '#8F360B')
            && str_contains((string) data_get($request->data(), 'message.body.content'), 'Segurex Latam'));
        Http::assertSent(fn (Request $request) => $request->url() === self::DERIVADOS_SEND_URL
            && str_contains((string) data_get($request->data(), 'message.body.content'), '#123456')
            && str_contains((string) data_get($request->data(), 'message.body.content'), 'Soluciones y Derivados'));
    }

    public function test_only_companies_with_the_toggle_and_a_valid_list_get_it(): void
    {
        $this->fakeMicrosoft();
        Announcement::factory()->create();
        $this->derivados->update(['weekly_digest_enabled' => false]);
        Company::factory()->forTenant('33333333-3333-3333-3333-333333333333', ['tercera.com'])->weeklyDigest()->create(['short_name' => 'Sin lista']);
        Company::factory()->forTenant('44444444-4444-4444-4444-444444444444', ['cuarta.com'])
            ->withListEmail('todos@cuarta.com', 'info@cuarta.com')->weeklyDigest()->inactive()->create(['short_name' => 'Inactiva']);

        $this->artisan('portal:send-weekly-digest')->assertSuccessful();

        $this->assertSame(1, $this->sendRequests());
        Http::assertSent(fn (Request $request) => $request->url() === self::SEGUREX_SEND_URL);
        $this->assertSame([$this->segurex->id], WeeklyDigest::query()->pluck('company_id')->all());
    }

    public function test_the_toggle_is_off_by_default(): void
    {
        $company = Company::factory()->create();

        $this->assertFalse($company->fresh()->weekly_digest_enabled);
    }

    public function test_a_sender_or_list_outside_the_company_domains_is_refused_without_calling_graph(): void
    {
        $this->fakeMicrosoft();
        Announcement::factory()->create();
        // Written behind the editor's back (it checks the domains).
        $this->derivados->forceFill(['mail_sender' => 'info@segurex.com'])->saveQuietly();

        $this->artisan('portal:send-weekly-digest')->assertSuccessful();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), self::DERIVADOS_TENANT)
            || $request->url() === 'https://graph.microsoft.com/v1.0/users/info@segurex.com/sendMail');
        $this->assertSame(1, $this->sendRequests());
        $row = WeeklyDigest::query()->where('company_id', $this->derivados->id)->sole();
        $this->assertSame(WeeklyDigest::STATUS_FAILED, $row->status);
        $this->assertSame('DomainMismatch', $row->error_code);
    }

    public function test_it_never_sends_twice_in_the_same_week(): void
    {
        $this->fakeMicrosoft();
        Announcement::factory()->create();

        $this->artisan('portal:send-weekly-digest')->assertSuccessful();
        $this->travel(1)->hours();
        $this->artisan('portal:send-weekly-digest')->assertSuccessful();
        $this->travel(4)->hours();
        $this->artisan('portal:send-weekly-digest')->assertSuccessful();

        $this->assertSame(2, $this->sendRequests());
        $this->assertSame(2, WeeklyDigest::query()->count());
    }

    public function test_a_later_run_catches_up_only_the_companies_without_a_row(): void
    {
        $this->fakeMicrosoft([self::SEGUREX_SEND_URL => Http::response(['error' => ['code' => 'ErrorAccessDenied']], 403)]);
        Announcement::factory()->create();
        $this->derivados->update(['weekly_digest_enabled' => false]);

        $this->artisan('portal:send-weekly-digest')->assertSuccessful();
        $this->assertSame(1, $this->sendRequests());

        // Turned on at 8:30; the 9:00 run sends it. The failed one is not retried.
        $this->travelTo(CarbonImmutable::parse('2026-11-09 09:00', 'America/Bogota'));
        $this->derivados->update(['weekly_digest_enabled' => true]);
        $this->artisan('portal:send-weekly-digest')->assertSuccessful();

        $this->assertSame(2, $this->sendRequests());
        Http::assertSent(fn (Request $request) => $request->url() === self::DERIVADOS_SEND_URL);
        $this->assertSame(WeeklyDigest::STATUS_FAILED, WeeklyDigest::query()->where('company_id', $this->segurex->id)->sole()->status);
        $this->assertSame(WeeklyDigest::STATUS_SENT, WeeklyDigest::query()->where('company_id', $this->derivados->id)->sole()->status);
    }

    public function test_next_monday_is_a_new_week(): void
    {
        $this->fakeMicrosoft();
        Announcement::factory()->mandatory()->create(['starts_at' => now()->subDays(30)]);

        $this->artisan('portal:send-weekly-digest')->assertSuccessful();
        $this->travelTo(CarbonImmutable::parse('2026-11-16 07:00', 'America/Bogota'));
        $this->artisan('portal:send-weekly-digest')->assertSuccessful();

        $this->assertSame(4, $this->sendRequests());
        $this->assertSame(['2026-11-09', '2026-11-16'], WeeklyDigest::query()->where('company_id', $this->segurex->id)
            ->orderBy('week_start')->get()->map(fn (WeeklyDigest $row) => $row->week_start->toDateString())->all());
    }

    public function test_it_only_sends_on_monday_from_seven_until_the_noon_run(): void
    {
        $this->fakeMicrosoft();
        Announcement::factory()->mandatory()->create(['starts_at' => now()->subDays(30)]);

        foreach (['2026-11-08 08:00', '2026-11-09 06:59', '2026-11-09 13:00', '2026-11-09 18:00', '2026-11-10 08:00'] as $moment) {
            $this->travelTo(CarbonImmutable::parse($moment, 'America/Bogota'));
            $this->artisan('portal:send-weekly-digest')->assertSuccessful();
        }

        Http::assertNothingSent();
        $this->assertSame(0, WeeklyDigest::query()->count());

        // The noon run, a few minutes late, still sends.
        $this->travelTo(CarbonImmutable::parse('2026-11-09 12:03', 'America/Bogota'));
        $this->artisan('portal:send-weekly-digest')->assertSuccessful();

        $this->assertSame(2, $this->sendRequests());
    }

    public function test_it_reads_monday_in_bogota_whatever_the_server_clock(): void
    {
        $this->fakeMicrosoft();
        Announcement::factory()->mandatory()->create(['starts_at' => now()->subDays(30)]);

        // 2:00 UTC on Monday is 21:00 on Sunday in Bogotá.
        $this->travelTo(CarbonImmutable::parse('2026-11-09 02:00', 'UTC'));
        $this->artisan('portal:send-weekly-digest')->assertSuccessful();
        Http::assertNothingSent();

        // 12:30 UTC on Monday is 7:30 in Bogotá.
        $this->travelTo(CarbonImmutable::parse('2026-11-09 12:30', 'UTC'));
        $this->artisan('portal:send-weekly-digest')->assertSuccessful();
        $this->assertSame(2, $this->sendRequests());
    }

    public function test_the_announcements_are_this_week_s_and_the_mandatory_ones_still_in_force(): void
    {
        $this->fakeMicrosoft();
        $recent = Announcement::factory()->create(['title' => 'Nuevo horario de cafetería', 'starts_at' => CarbonImmutable::parse('2026-11-05 10:00', 'America/Bogota')]);
        Announcement::factory()->create(['title' => 'Anuncio viejo', 'starts_at' => now()->subDays(10)]);
        $mandatory = Announcement::factory()->mandatory()->create(['title' => 'Política de seguridad', 'starts_at' => now()->subDays(20)]);
        Announcement::factory()->mandatory()->expired()->create(['title' => 'Obligatorio vencido']);
        Announcement::factory()->mandatory()->withdrawn()->create(['title' => 'Obligatorio retirado', 'starts_at' => now()->subDays(2)]);
        Announcement::factory()->scheduled()->create(['title' => 'Anuncio programado']);
        Announcement::factory()->withdrawn()->create(['title' => 'Anuncio retirado', 'starts_at' => now()->subDays(2)]);

        $this->artisan('portal:send-weekly-digest')->assertSuccessful();

        $content = $this->contentFor(self::SEGUREX_SEND_URL);
        $this->assertStringContainsString('Nuevo horario de cafetería', $content);
        $this->assertStringContainsString('jueves 5 de noviembre', $content);
        $this->assertStringContainsString('href="https://apps.segurexlatam.net/anuncios/'.$recent->id.'"', $content);
        $this->assertStringContainsString('Política de seguridad', $content);
        $this->assertStringContainsString('href="https://apps.segurexlatam.net/anuncios/'.$mandatory->id.'"', $content);
        $this->assertStringContainsString('Lectura obligatoria', $content);
        foreach (['Anuncio viejo', 'Obligatorio vencido', 'Obligatorio retirado', 'Anuncio programado', 'Anuncio retirado'] as $title) {
            $this->assertStringNotContainsString($title, $content);
        }
    }

    public function test_only_what_everybody_of_the_company_sees_goes_in_its_digest(): void
    {
        $this->fakeMicrosoft();
        Announcement::factory()->forCompanies($this->derivados)->create(['title' => 'Solo para Derivados']);
        Announcement::factory()->forCompanies($this->segurex, $this->derivados)->create(['title' => 'Para las dos empresas']);
        Announcement::factory()->forCompanies($this->segurex)->create(['title' => 'Solo para Segurex']);
        Event::factory()->forCompanies($this->derivados)->create(['title' => 'Evento de Derivados', 'starts_at' => '2026-11-11 15:00:00', 'ends_at' => '2026-11-11 16:00:00']);
        Event::factory()->forCompanies($this->segurex)->create(['title' => 'Evento de Segurex', 'starts_at' => '2026-11-11 15:00:00', 'ends_at' => '2026-11-11 16:00:00']);

        $this->artisan('portal:send-weekly-digest')->assertSuccessful();

        $segurex = $this->contentFor(self::SEGUREX_SEND_URL);
        $this->assertStringContainsString('Para las dos empresas', $segurex);
        $this->assertStringContainsString('Solo para Segurex', $segurex);
        $this->assertStringContainsString('Evento de Segurex', $segurex);
        $this->assertStringNotContainsString('Solo para Derivados', $segurex);
        $this->assertStringNotContainsString('Evento de Derivados', $segurex);

        $derivados = $this->contentFor(self::DERIVADOS_SEND_URL);
        $this->assertStringContainsString('Solo para Derivados', $derivados);
        $this->assertStringContainsString('Evento de Derivados', $derivados);
        $this->assertStringNotContainsString('Solo para Segurex', $derivados);

        // Audiences are whole companies: whatever the digest of Segurex
        // lists, any member of Segurex sees in the portal.
        $member = User::factory()->microsoft($this->segurex)->create();
        $visible = Announcement::query()->visibleTo($member)->pluck('title')->all();
        $this->assertEqualsCanonicalizing(['Para las dos empresas', 'Solo para Segurex'], $visible);
    }

    public function test_the_week_dates_are_holidays_events_and_yearly_dates_from_monday_to_sunday(): void
    {
        // Monday 16 November 2026: Independencia de Cartagena, moved to Monday.
        $this->travelTo(CarbonImmutable::parse('2026-11-16 07:00', 'America/Bogota'));
        $this->fakeMicrosoft();
        Event::factory()->create(['title' => 'Comité de seguridad', 'starts_at' => '2026-11-18 15:00:00', 'ends_at' => '2026-11-18 17:00:00']);
        Event::factory()->allDay('2019-11-20')->yearly()->create(['title' => 'Aniversario de la empresa']);
        Event::factory()->create(['title' => 'Evento del domingo', 'starts_at' => '2026-11-22 10:00:00', 'ends_at' => '2026-11-22 11:00:00']);
        Event::factory()->create(['title' => 'Evento de la otra semana', 'starts_at' => '2026-11-23 09:00:00', 'ends_at' => '2026-11-23 10:00:00']);
        Event::factory()->withdrawn()->create(['title' => 'Evento retirado', 'starts_at' => '2026-11-18 09:00:00', 'ends_at' => '2026-11-18 10:00:00']);
        Event::factory()->scheduled()->create(['title' => 'Evento sin publicar', 'starts_at' => '2026-11-19 09:00:00', 'ends_at' => '2026-11-19 10:00:00']);

        $this->artisan('portal:send-weekly-digest')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === self::SEGUREX_SEND_URL
            && data_get($request->data(), 'message.subject') === 'Resumen de la semana del 16 al 22 de noviembre');
        $content = $this->contentFor(self::SEGUREX_SEND_URL);
        $this->assertStringContainsString('Independencia de Cartagena', $content);
        $this->assertStringContainsString('Puente festivo', $content);
        $this->assertStringContainsString('Comité de seguridad', $content);
        $this->assertStringContainsString('Miércoles 18 de noviembre · 15:00 a 17:00', $content);
        $this->assertStringContainsString('Aniversario de la empresa', $content);
        $this->assertStringContainsString('Cada año', $content);
        $this->assertStringContainsString('Evento del domingo', $content);
        $this->assertStringNotContainsString('Evento de la otra semana', $content);
        $this->assertStringNotContainsString('Evento retirado', $content);
        $this->assertStringNotContainsString('Evento sin publicar', $content);
        // The holiday comes first, as on Monday.
        $this->assertLessThan(strpos($content, 'Comité de seguridad'), strpos($content, 'Independencia de Cartagena'));
    }

    public function test_a_holiday_on_another_weekday_is_a_plain_festivo(): void
    {
        // Tuesday 8 December 2026: Inmaculada Concepción stays on its day.
        $this->travelTo(CarbonImmutable::parse('2026-12-07 07:00', 'America/Bogota'));
        $this->fakeMicrosoft();

        $this->artisan('portal:send-weekly-digest')->assertSuccessful();

        $content = $this->contentFor(self::SEGUREX_SEND_URL);
        $this->assertStringContainsString('Inmaculada Concepción', $content);
        $this->assertStringContainsString('Festivo', $content);
        $this->assertStringNotContainsString('Puente festivo', $content);
    }

    public function test_birthdays_and_work_anniversaries_of_the_week_never_show_a_year(): void
    {
        $this->fakeMicrosoft();
        $this->person($this->segurex, 'Luis Gómez', birthday: [11, 11]);
        $this->person($this->segurex, 'Marta Ruiz', birthday: [11, 12], shown: false);
        $this->person($this->segurex, 'Carlos Díaz', hired: '2021-11-13');
        $this->person($this->segurex, 'Paula Nieto', birthday: [11, 16], hired: '2020-11-17');
        $this->person($this->derivados, 'Jorge Ríos', birthday: [11, 10]);

        $this->artisan('portal:send-weekly-digest')->assertSuccessful();

        $content = $this->contentFor(self::SEGUREX_SEND_URL);
        $this->assertStringContainsString('Luis Gómez', $content);
        $this->assertStringContainsString('Cumpleaños', $content);
        $this->assertStringContainsString('Miércoles 11 de noviembre', $content);
        $this->assertStringContainsString('Carlos Díaz', $content);
        $this->assertStringContainsString('5 años en Segurex', $content);
        $this->assertStringContainsString('Viernes 13 de noviembre', $content);
        // Hidden birthday, next week's dates and another company's people.
        $this->assertStringNotContainsString('Marta Ruiz', $content);
        $this->assertStringNotContainsString('Paula Nieto', $content);
        $this->assertStringNotContainsString('Jorge Ríos', $content);
        $this->assertStringNotContainsString('2021', $content);

        $this->assertStringContainsString('Jorge Ríos', $this->contentFor(self::DERIVADOS_SEND_URL));
    }

    public function test_it_links_to_the_portal_home(): void
    {
        $this->fakeMicrosoft();
        Announcement::factory()->create();

        $this->artisan('portal:send-weekly-digest')->assertSuccessful();

        $content = $this->contentFor(self::SEGUREX_SEND_URL);
        $this->assertStringContainsString('href="https://apps.segurexlatam.net"', $content);
        $this->assertStringContainsString('Abrir el portal', $content);
    }

    public function test_without_anything_to_tell_it_sends_nothing_and_records_it_as_empty(): void
    {
        $this->fakeMicrosoft();
        Announcement::factory()->create(['starts_at' => now()->subDays(10)]);

        $this->artisan('portal:send-weekly-digest')
            ->expectsOutputToContain('2 sin contenido')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(
            [WeeklyDigest::STATUS_EMPTY, WeeklyDigest::STATUS_EMPTY],
            WeeklyDigest::query()->pluck('status')->all(),
        );

        // Something published later that Monday does not send it.
        Announcement::factory()->create();
        $this->travel(1)->hours();
        $this->artisan('portal:send-weekly-digest')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_titles_never_become_markdown_or_html(): void
    {
        $this->fakeMicrosoft();
        // Written behind the editors' backs (they refuse line breaks in titles).
        Announcement::factory()->forCompanies($this->segurex)->create([
            'title' => "Aviso\n\n[Entra aquí](https://evil.example/login) ![x](https://evil.example/t.png) <b>negrita</b>\r\n\r\nFin",
        ]);
        Event::factory()->forCompanies($this->segurex)->create([
            'title' => "Reunión\n\n<script>alert(1)</script> **fuerte**",
            'starts_at' => '2026-11-11 15:00:00',
            'ends_at' => '2026-11-11 16:00:00',
        ]);
        $this->person($this->segurex, "Ana\n\n[clic](https://evil.example/p)", birthday: [11, 11]);

        $this->artisan('portal:send-weekly-digest')->assertSuccessful();

        $content = $this->contentFor(self::SEGUREX_SEND_URL);
        $this->assertStringNotContainsString('href="https://evil.example', $content);
        $this->assertStringNotContainsString('src="https://evil.example', $content);
        $this->assertStringNotContainsString('<b>negrita</b>', $content);
        $this->assertStringNotContainsString('<script>', $content);
        $this->assertStringNotContainsString('<strong>fuerte</strong>', $content);
        $this->assertStringContainsString('Aviso [Entra aquí](https://evil.example/login)', $content);
        $this->assertStringContainsString('&lt;b&gt;negrita&lt;/b&gt;', $content);
        $this->assertStringContainsString('&lt;script&gt;', $content);
        $this->assertStringContainsString('Ana [clic](https://evil.example/p)', $content);
    }

    public function test_a_failure_is_recorded_with_a_short_code_and_the_others_go_on(): void
    {
        Log::spy();
        $this->fakeMicrosoft([self::SEGUREX_SEND_URL => Http::response(['error' => ['code' => 'ErrorAccessDenied', 'message' => 'Access is denied for token-for-x']], 403)]);
        Announcement::factory()->create();

        $this->artisan('portal:send-weekly-digest')
            ->expectsOutputToContain('1 con error')
            ->assertSuccessful();

        $failed = WeeklyDigest::query()->where('company_id', $this->segurex->id)->sole();
        $this->assertSame(WeeklyDigest::STATUS_FAILED, $failed->status);
        $this->assertSame('ErrorAccessDenied', $failed->error_code);
        $this->assertNull($failed->sent_at);
        $this->assertSame(WeeklyDigest::STATUS_SENT, WeeklyDigest::query()->where('company_id', $this->derivados->id)->sole()->status);

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) {
            $logged = json_encode($context);

            return $context['company_id'] === $this->segurex->id
                && $context['error'] === 'ErrorAccessDenied'
                && ! str_contains($logged, 'token-for')
                && ! str_contains($logged, 'SECRET-VALUE');
        });
    }

    public function test_a_refused_token_and_an_unreachable_graph_get_short_codes(): void
    {
        $this->fakeMicrosoft(
            [self::DERIVADOS_SEND_URL => Http::failedConnection()],
            fn (Request $request) => str_contains($request->url(), self::SEGUREX_TENANT)
                ? Http::response(['error' => 'invalid_client', 'error_codes' => [7000215]], 401)
                : Http::response(['access_token' => 'token-for-'.self::DERIVADOS_TENANT, 'expires_in' => 3600]),
        );
        Announcement::factory()->create();

        $this->artisan('portal:send-weekly-digest')->assertSuccessful();

        $this->assertSame('AADSTS7000215', WeeklyDigest::query()->where('company_id', $this->segurex->id)->sole()->error_code);
        $this->assertSame('Unavailable', WeeklyDigest::query()->where('company_id', $this->derivados->id)->sole()->error_code);
    }

    public function test_a_row_left_sending_by_a_crash_is_marked_interrupted_and_never_resent(): void
    {
        $this->fakeMicrosoft();
        Announcement::factory()->create();
        $row = WeeklyDigest::query()->create([
            'company_id' => $this->segurex->id,
            'week_start' => '2026-11-09',
            'status' => WeeklyDigest::STATUS_SENDING,
            'attempted_at' => now(),
        ]);

        $this->travel(16)->minutes();
        $this->artisan('portal:send-weekly-digest')->assertSuccessful();

        $row->refresh();
        $this->assertSame(WeeklyDigest::STATUS_FAILED, $row->status);
        $this->assertSame('Interrupted', $row->error_code);
        Http::assertNotSent(fn (Request $request) => $request->url() === self::SEGUREX_SEND_URL);
        Http::assertSent(fn (Request $request) => $request->url() === self::DERIVADOS_SEND_URL);
    }

    public function test_a_recent_row_still_sending_is_left_alone(): void
    {
        $this->fakeMicrosoft();
        Announcement::factory()->create();
        $row = WeeklyDigest::query()->create([
            'company_id' => $this->segurex->id,
            'week_start' => '2026-11-09',
            'status' => WeeklyDigest::STATUS_SENDING,
            'attempted_at' => now(),
        ]);

        $this->travel(5)->minutes();
        $this->artisan('portal:send-weekly-digest')->assertSuccessful();

        $this->assertSame(WeeklyDigest::STATUS_SENDING, $row->fresh()->status);
        Http::assertNotSent(fn (Request $request) => $request->url() === self::SEGUREX_SEND_URL);
    }

    public function test_one_row_per_company_and_week(): void
    {
        WeeklyDigest::query()->create(['company_id' => $this->segurex->id, 'week_start' => '2026-11-09', 'status' => WeeklyDigest::STATUS_SENT]);

        $this->expectException(UniqueConstraintViolationException::class);

        WeeklyDigest::query()->create(['company_id' => $this->segurex->id, 'week_start' => '2026-11-09', 'status' => WeeklyDigest::STATUS_SENDING]);
    }

    public function test_deleting_a_company_deletes_its_digests(): void
    {
        WeeklyDigest::query()->create(['company_id' => $this->segurex->id, 'week_start' => '2026-11-09', 'status' => WeeklyDigest::STATUS_SENT]);

        $this->segurex->delete();

        $this->assertSame(0, WeeklyDigest::query()->count());
    }

    public function test_without_anything_to_send_it_stays_quiet(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 08:00', 'America/Bogota'));

        $this->artisan('portal:send-weekly-digest')
            ->doesntExpectOutputToContain('Resumen')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_it_is_scheduled_on_mondays_every_hour_from_seven_to_noon_in_bogota(): void
    {
        /** @var ScheduledEvent|null $event */
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (ScheduledEvent $event) => str_contains((string) $event->command, 'portal:send-weekly-digest'));

        $this->assertNotNull($event, 'portal:send-weekly-digest is not scheduled.');
        $this->assertSame('0 7-12 * * 1', $event->expression);
        $this->assertSame('America/Bogota', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
    }

    public function test_the_announcements_start_where_last_week_s_digest_left_off(): void
    {
        $this->fakeMicrosoft();
        // Last week's digest went out late, in the 12:00 catch-up (any status counts).
        $this->previousDigest($this->segurex, '2026-11-02 12:00', WeeklyDigest::STATUS_EMPTY);
        Announcement::factory()->create(['title' => 'Antes del resumen pasado', 'starts_at' => CarbonImmutable::parse('2026-11-02 11:00', 'America/Bogota')]);
        Announcement::factory()->create(['title' => 'Justo en el resumen pasado', 'starts_at' => CarbonImmutable::parse('2026-11-02 12:00', 'America/Bogota')]);
        Announcement::factory()->create(['title' => 'Después del resumen pasado', 'starts_at' => CarbonImmutable::parse('2026-11-02 12:30', 'America/Bogota')]);

        $this->artisan('portal:send-weekly-digest')->assertSuccessful();

        $content = $this->contentFor(self::SEGUREX_SEND_URL);
        $this->assertStringNotContainsString('Antes del resumen pasado', $content);
        $this->assertStringNotContainsString('Justo en el resumen pasado', $content);
        $this->assertStringContainsString('Después del resumen pasado', $content);
    }

    public function test_a_late_run_does_not_skip_what_came_after_last_week_s_on_time_run(): void
    {
        $this->fakeMicrosoft();
        $this->previousDigest($this->segurex, '2026-11-02 07:00', WeeklyDigest::STATUS_FAILED);
        Announcement::factory()->create(['title' => 'Lunes pasado a las 9', 'starts_at' => CarbonImmutable::parse('2026-11-02 09:00', 'America/Bogota')]);
        Announcement::factory()->create(['title' => 'Hoy a las 11', 'starts_at' => CarbonImmutable::parse('2026-11-09 11:00', 'America/Bogota')]);

        // This week's digest goes out in the 12:00 catch-up.
        $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00', 'America/Bogota'));
        $this->artisan('portal:send-weekly-digest')->assertSuccessful();

        $content = $this->contentFor(self::SEGUREX_SEND_URL);
        $this->assertStringContainsString('Lunes pasado a las 9', $content);
        $this->assertStringContainsString('Hoy a las 11', $content);
    }

    public function test_without_last_week_s_digest_the_announcements_start_last_monday_at_seven(): void
    {
        $this->fakeMicrosoft();
        // Another company's row does not count.
        $this->previousDigest($this->derivados, '2026-11-02 12:00', WeeklyDigest::STATUS_SENT);
        Announcement::factory()->create(['title' => 'Lunes pasado 6:59', 'starts_at' => CarbonImmutable::parse('2026-11-02 06:59', 'America/Bogota')]);
        Announcement::factory()->create(['title' => 'Lunes pasado 7:01', 'starts_at' => CarbonImmutable::parse('2026-11-02 07:01', 'America/Bogota')]);

        $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00', 'America/Bogota'));
        $this->artisan('portal:send-weekly-digest')->assertSuccessful();

        $content = $this->contentFor(self::SEGUREX_SEND_URL);
        $this->assertStringNotContainsString('Lunes pasado 6:59', $content);
        $this->assertStringContainsString('Lunes pasado 7:01', $content);
    }

    public function test_the_week_is_read_in_bogota_even_when_the_app_stores_utc(): void
    {
        $previous = date_default_timezone_get();

        try {
            config(['app.timezone' => 'UTC']);
            date_default_timezone_set('UTC');
            $this->fakeMicrosoft();
            // Sunday 15 November 21:00 in Bogotá, stored in UTC.
            Event::factory()->create(['title' => 'Cierre del domingo en la noche', 'starts_at' => '2026-11-16 02:00:00', 'ends_at' => '2026-11-16 03:00:00']);
            // Monday 16 November 08:00 in Bogotá: next week.
            Event::factory()->create(['title' => 'Evento del lunes siguiente', 'starts_at' => '2026-11-16 13:00:00', 'ends_at' => '2026-11-16 14:00:00']);

            $this->artisan('portal:send-weekly-digest')->assertSuccessful();

            $content = $this->contentFor(self::SEGUREX_SEND_URL);
            $this->assertStringContainsString('Cierre del domingo en la noche', $content);
            $this->assertStringNotContainsString('Evento del lunes siguiente', $content);
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function test_a_week_across_the_new_year_names_both_years_and_finds_january_dates(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-28 07:00', 'America/Bogota'));
        $this->fakeMicrosoft();
        $this->person($this->segurex, 'Luis Gómez', birthday: [1, 2]);
        Event::factory()->allDay('2020-01-02')->yearly()->create(['title' => 'Aniversario de la empresa']);

        $this->artisan('portal:send-weekly-digest')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === self::SEGUREX_SEND_URL
            && data_get($request->data(), 'message.subject') === 'Resumen de la semana del 28 de diciembre de 2026 al 3 de enero de 2027');
        $content = $this->contentFor(self::SEGUREX_SEND_URL);
        $this->assertStringContainsString('Año Nuevo', $content);
        $this->assertStringContainsString('Sábado 2 de enero</strong> · Luis Gómez: Cumpleaños', $content);
        $this->assertStringContainsString('Sábado 2 de enero de 2027 · Todo el día</strong> · Cada año: Aniversario de la empresa', $content);
    }

    public function test_29_february_is_celebrated_on_28_february_in_a_common_year(): void
    {
        // Monday 22 February 2027; 2027 is a common year.
        $this->travelTo(CarbonImmutable::parse('2027-02-22 07:00', 'America/Bogota'));
        $this->fakeMicrosoft();
        $this->person($this->segurex, 'Ana Ruiz', birthday: [2, 29], hired: '2020-02-29');

        $this->artisan('portal:send-weekly-digest')->assertSuccessful();

        $content = $this->contentFor(self::SEGUREX_SEND_URL);
        $this->assertStringContainsString('Domingo 28 de febrero</strong> · Ana Ruiz: Cumpleaños', $content);
        $this->assertStringContainsString('Domingo 28 de febrero</strong> · Ana Ruiz: 7 años en Segurex', $content);
    }

    public function test_an_event_running_since_last_week_shows_as_under_way(): void
    {
        $this->fakeMicrosoft();
        Event::factory()->allDay('2026-11-06', '2026-11-10')->create(['title' => 'Semana de la seguridad']);

        $this->artisan('portal:send-weekly-digest')->assertSuccessful();

        $this->assertStringContainsString(
            'En curso · hasta el martes 10 de noviembre</strong> · Evento: Semana de la seguridad',
            $this->contentFor(self::SEGUREX_SEND_URL),
        );
    }

    public function test_a_digest_that_cannot_be_built_records_nothing_and_the_next_run_tries_again(): void
    {
        Log::spy();
        $this->fakeMicrosoft();
        Announcement::factory()->create();
        $builder = $this->builderDouble();
        $builder->before = function (Company $company): void {
            throw new RuntimeException('CONTENIDO-SECRETO de '.$company->name);
        };

        $this->artisan('portal:send-weekly-digest')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, WeeklyDigest::query()->count());
        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context) {
            $logged = json_encode($context);

            return $context['company_id'] === $this->segurex->id
                && $context['exception'] === RuntimeException::class
                && str_contains((string) $context['at'], 'SendWeeklyDigestCommandTest.php:')
                && ! str_contains($logged, 'CONTENIDO-SECRETO');
        });

        $builder->before = null;
        $this->travel(1)->hours();
        $this->artisan('portal:send-weekly-digest')->assertSuccessful();

        $this->assertSame(2, $this->sendRequests());
        $this->assertSame(2, WeeklyDigest::query()->where('status', WeeklyDigest::STATUS_SENT)->count());
    }

    public function test_a_run_that_loses_the_week_to_another_run_never_calls_graph(): void
    {
        $this->fakeMicrosoft();
        Announcement::factory()->create();
        // Another run records Segurex's week while this one builds it.
        $this->builderDouble()->before = function (Company $company): void {
            if ($company->is($this->segurex)) {
                WeeklyDigest::query()->create(['company_id' => $company->id, 'week_start' => '2026-11-09', 'status' => WeeklyDigest::STATUS_SENT, 'attempted_at' => now()]);
            }
        };

        $this->artisan('portal:send-weekly-digest')->assertSuccessful();

        Http::assertNotSent(fn (Request $request) => $request->url() === self::SEGUREX_SEND_URL);
        Http::assertSent(fn (Request $request) => $request->url() === self::DERIVADOS_SEND_URL);
        $this->assertSame(WeeklyDigest::STATUS_SENT, WeeklyDigest::query()->where('company_id', $this->segurex->id)->sole()->status);
    }

    public function test_a_week_past_the_holiday_calendar_is_logged_and_built_without_holidays(): void
    {
        Log::spy();

        $content = app(WeeklyDigestBuilder::class)->build($this->segurex, CarbonImmutable::parse('9999-12-27', 'America/Bogota'));

        $this->assertSame([], $content->dates);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => str_contains($message, 'holiday')
            && $context['company_id'] === $this->segurex->id
            && $context['week_start'] === '9999-12-27');
    }

    private function previousDigest(Company $company, string $attemptedAt, string $status): WeeklyDigest
    {
        $at = CarbonImmutable::parse($attemptedAt, 'America/Bogota');

        return WeeklyDigest::query()->create([
            'company_id' => $company->id,
            'week_start' => $at->toDateString(),
            'status' => $status,
            'attempted_at' => $at,
        ]);
    }

    /**
     * The real builder, with a hook that runs before each company's
     * digest is built (to make it fail, or to record the week first).
     */
    private function builderDouble(): object
    {
        $double = new class(app(ColombianHolidays::class)) extends WeeklyDigestBuilder
        {
            public ?Closure $before = null;

            public function build(Company $company, CarbonImmutable $weekStart, ?CarbonImmutable $runAt = null): WeeklyDigestContent
            {
                if ($this->before !== null) {
                    ($this->before)($company);
                }

                return parent::build($company, $weekStart, $runAt);
            }
        };
        $this->app->instance(WeeklyDigestBuilder::class, $double);

        return $double;
    }

    private function person(Company $company, string $name, ?array $birthday = null, bool $shown = true, ?string $hired = null): DirectoryEntry
    {
        $factory = User::factory()->microsoft($company);
        if ($birthday !== null) {
            $factory = $factory->birthday($birthday[0], $birthday[1], $shown);
        }
        $user = $factory->create(['name' => $name]);

        return DirectoryEntry::factory()->forCompany($company)->create([
            'tenant_id' => $user->tenant_id,
            'azure_oid' => $user->azure_oid,
            'display_name' => $name,
            'email' => Str::slug($name, '.').'@'.($company->domains[0] ?? 'example.com'),
            'hire_date' => $hired,
        ]);
    }

    private function contentFor(string $url): string
    {
        $request = Http::recorded(fn (Request $request) => $request->url() === $url)->first()[0] ?? null;
        $this->assertNotNull($request, "Nothing was sent to {$url}.");

        return (string) data_get($request->data(), 'message.body.content');
    }

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
