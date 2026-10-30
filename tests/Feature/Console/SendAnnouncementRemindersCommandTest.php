<?php

namespace Tests\Feature\Console;

use App\Announcements\AnnouncementReminders;
use App\Models\Announcement;
use App\Models\AnnouncementReminder;
use App\Models\AnnouncementReminderDelivery;
use App\Models\Company;
use App\Models\Person;
use App\Models\User;
use App\Notifications\AnnouncementReminderNotice;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/**
 * portal:send-announcement-reminders (odd/tasks/ajustes-inicio-y-tareas.md
 * T5): every minute the cron sends a bounded batch of the requested
 * reminders, one email per person who has not confirmed yet, never twice
 * for the same reminder.
 */
class SendAnnouncementRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private Company $derivados;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00', 'America/Bogota'));

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);
        $this->derivados = Company::factory()->create(['name' => 'Soluciones y Derivados', 'short_name' => 'Derivados']);
        $this->admin = User::factory()->create(['name' => 'Jhon Castro']);
    }

    public function test_it_sends_one_email_per_missing_person(): void
    {
        $announcement = $this->mandatory();
        [$anaSegurex, $anaDerivados] = $this->linked('Ana Pérez');
        $luis = User::factory()->microsoft($this->derivados)->create(['name' => 'Luis Gómez']);
        $read = User::factory()->microsoft($this->segurex)->create(['name' => 'Ya leyó']);
        $announcement->markReadBy($read);
        $reminder = $this->request($announcement);

        $this->artisan('portal:send-announcement-reminders')
            ->expectsOutputToContain('2 correos enviados')
            ->assertSuccessful();

        // Ana: one email, to the lower id of her accounts in the audience.
        Notification::assertSentToTimes($anaSegurex, AnnouncementReminderNotice::class, 1);
        Notification::assertNotSentTo($anaDerivados, AnnouncementReminderNotice::class);
        Notification::assertSentToTimes($luis, AnnouncementReminderNotice::class, 1);
        Notification::assertNotSentTo($read, AnnouncementReminderNotice::class);
        Notification::assertCount(2);

        $reminder->refresh();
        $this->assertNotNull($reminder->finished_at);
        $this->assertSame(2, $reminder->deliveries()->where('status', AnnouncementReminderDelivery::STATUS_SENT)->count());
    }

    public function test_the_email_goes_to_an_account_in_the_audience_with_its_company_brand(): void
    {
        // Only for Derivados: Ana's Segurex account (the lower id) does not see it.
        $announcement = Announcement::factory()->mandatory()->forCompanies($this->derivados)->create(['title' => 'Inventario de fin de año']);
        [$anaSegurex, $anaDerivados] = $this->linked('Ana Pérez');
        $this->request($announcement);

        $this->artisan('portal:send-announcement-reminders')->assertSuccessful();

        Notification::assertNotSentTo($anaSegurex, AnnouncementReminderNotice::class);
        Notification::assertSentTo($anaDerivados, AnnouncementReminderNotice::class, function (AnnouncementReminderNotice $notice, array $channels, User $notifiable) use ($announcement) {
            $mail = $notice->toMail($notifiable);

            return $channels === ['mail']
                && $mail->viewData['brand']->name === 'Soluciones y Derivados'
                && $mail->actionUrl === route('announcements.show', $announcement);
        });
    }

    public function test_the_email_has_the_title_and_a_link_but_not_the_body(): void
    {
        $announcement = $this->mandatory(['title' => 'Nueva política de contraseñas', 'body' => 'Texto que solo se lee en el portal.']);
        $luis = User::factory()->microsoft($this->segurex)->create(['name' => 'Luis Gómez']);

        $mail = (new AnnouncementReminderNotice($announcement))->toMail($luis);
        $text = implode("\n", [...$mail->introLines, ...$mail->outroLines]);

        $this->assertSame('Recordatorio: confirma la lectura de «Nueva política de contraseñas»', $mail->subject);
        $this->assertStringContainsString('Nueva política de contraseñas', $text);
        $this->assertStringContainsString('confirmar que lo leíste', $text);
        $this->assertStringNotContainsString('Texto que solo se lee en el portal.', $text);
        $this->assertSame('Ver el anuncio', $mail->actionText);
        $this->assertSame(route('announcements.show', $announcement), $mail->actionUrl);
        $this->assertSame('Segurex Latam', $mail->viewData['brand']->name);

        // The rendered email, as Graph would send it.
        $html = (string) $mail->render();
        $this->assertStringContainsString('Ver el anuncio', $html);
        $this->assertStringNotContainsString('Texto que solo se lee en el portal.', $html);
    }

    public function test_someone_who_confirms_before_the_cron_runs_is_skipped(): void
    {
        $announcement = $this->mandatory();
        [, $anaDerivados] = $this->linked('Ana Pérez');
        $luis = User::factory()->microsoft($this->segurex)->create(['name' => 'Luis Gómez']);
        $reminder = $this->request($announcement);

        // Ana confirms with her other account in the meantime.
        $announcement->markReadBy($anaDerivados);

        $this->artisan('portal:send-announcement-reminders')->assertSuccessful();

        Notification::assertSentToTimes($luis, AnnouncementReminderNotice::class, 1);
        Notification::assertCount(1);
        $this->assertSame(2, $reminder->fresh()->missing_count);
    }

    public function test_it_sends_in_batches_and_never_twice(): void
    {
        $announcement = $this->mandatory();
        $people = User::factory()->microsoft($this->segurex)->count(AnnouncementReminders::BATCH + 2)->create();
        $reminder = $this->request($announcement);

        $this->artisan('portal:send-announcement-reminders')->assertSuccessful();
        Notification::assertCount(AnnouncementReminders::BATCH);
        $this->assertNull($reminder->fresh()->finished_at);

        $this->artisan('portal:send-announcement-reminders')->assertSuccessful();
        Notification::assertCount(AnnouncementReminders::BATCH + 2);
        $this->assertNotNull($reminder->fresh()->finished_at);

        // Finished: nothing more, ever.
        $this->artisan('portal:send-announcement-reminders')->assertSuccessful();
        Notification::assertCount(AnnouncementReminders::BATCH + 2);
        foreach ($people as $person) {
            Notification::assertSentToTimes($person, AnnouncementReminderNotice::class, 1);
        }
        $this->assertSame(AnnouncementReminders::BATCH + 2, $reminder->deliveries()->count());
    }

    public function test_a_person_already_recorded_for_the_reminder_is_not_mailed_again(): void
    {
        $announcement = $this->mandatory();
        $luis = User::factory()->microsoft($this->segurex)->create(['name' => 'Luis Gómez']);
        $carla = User::factory()->microsoft($this->segurex)->create(['name' => 'Carla Ruiz']);
        $reminder = $this->request($announcement);

        // A run that died after recording Luis, before or after mailing him.
        app(AnnouncementReminders::class)->send(1);
        Notification::assertSentToTimes($luis, AnnouncementReminderNotice::class, 1);
        $reminder->deliveries()->update(['status' => AnnouncementReminderDelivery::STATUS_PENDING]);

        $this->artisan('portal:send-announcement-reminders')->assertSuccessful();

        Notification::assertSentToTimes($luis, AnnouncementReminderNotice::class, 1);
        Notification::assertSentToTimes($carla, AnnouncementReminderNotice::class, 1);
        Notification::assertCount(2);
        $this->assertNotNull($reminder->fresh()->finished_at);
    }

    public function test_a_mail_failure_is_recorded_and_does_not_stop_the_batch(): void
    {
        Log::spy();
        $announcement = $this->mandatory();
        $broken = User::factory()->microsoft($this->segurex)->create(['name' => 'Buzón lleno']);
        $luis = User::factory()->microsoft($this->segurex)->create(['name' => 'Luis Gómez']);
        $reminder = $this->request($announcement);

        $sent = [];
        Notification::shouldReceive('sendNow')->andReturnUsing(function (User $user) use ($broken, &$sent) {
            if ($user->is($broken)) {
                throw new RuntimeException('Graph said no.');
            }
            $sent[] = $user->id;
        });

        $this->artisan('portal:send-announcement-reminders')
            ->expectsOutputToContain('1 correos enviados, 1 con error')
            ->assertSuccessful();

        $this->assertSame([$luis->id], $sent);
        $this->assertSame(AnnouncementReminderDelivery::STATUS_FAILED, $reminder->deliveries()->where('user_id', $broken->id)->sole()->status);
        $this->assertSame(AnnouncementReminderDelivery::STATUS_SENT, $reminder->deliveries()->where('user_id', $luis->id)->sole()->status);
        $this->assertNotNull($reminder->fresh()->finished_at);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'Announcement reminder could not be sent.'
            && $context['user_id'] === $broken->id
            && $context['exception'] === RuntimeException::class)->once();

        // Not retried on the next run.
        $this->artisan('portal:send-announcement-reminders')->assertSuccessful();
        $this->assertSame([$luis->id], $sent);
    }

    public function test_a_withdrawn_announcement_stops_its_reminder(): void
    {
        $announcement = $this->mandatory();
        User::factory()->microsoft($this->segurex)->create();
        $reminder = $this->request($announcement);
        $announcement->withdraw();

        $this->artisan('portal:send-announcement-reminders')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNotNull($reminder->fresh()->finished_at);
    }

    public function test_without_requests_it_does_nothing(): void
    {
        $this->mandatory();
        User::factory()->microsoft($this->segurex)->create();

        $this->artisan('portal:send-announcement-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_it_runs_every_minute_without_overlapping(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'portal:send-announcement-reminders'));

        $this->assertNotNull($event, 'portal:send-announcement-reminders is not scheduled.');
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    /**
     * For Segurex and Derivados.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function mandatory(array $attributes = []): Announcement
    {
        return Announcement::factory()->mandatory()->forCompanies($this->segurex, $this->derivados)->create($attributes);
    }

    private function request(Announcement $announcement): AnnouncementReminder
    {
        return app(AnnouncementReminders::class)->request($announcement, $this->admin);
    }

    /**
     * One person with an account in Segurex (the lower id) and one in Derivados.
     *
     * @return array{User, User}
     */
    private function linked(string $name): array
    {
        $person = Person::query()->create();

        return [
            User::factory()->microsoft($this->segurex)->create(['name' => $name, 'person_id' => $person->id]),
            User::factory()->microsoft($this->derivados)->create(['name' => $name, 'person_id' => $person->id]),
        ];
    }
}
