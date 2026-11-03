<?php

namespace Tests\Feature\Console;

use App\Models\Announcement;
use App\Models\Company;
use App\Models\Person;
use App\Models\PushDelivery;
use App\Models\PushSubscription;
use App\Models\User;
use App\Push\PushSender;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Push\FakePushSender;
use Tests\Feature\Push\PushTestKeys;
use Tests\TestCase;

/**
 * portal:send-announcement-pushes (odd/tasks/avisos.md T2): every minute
 * the cron sends the announcements published with «Enviar al celular» to
 * the devices of the accounts that see them on the home, in batches, one
 * delivery per device, never twice. No network: a fake sender.
 */
class SendAnnouncementPushesCommandTest extends TestCase
{
    use PushTestKeys;
    use RefreshDatabase;

    private Company $segurex;

    private Company $derivados;

    private FakePushSender $sender;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurePush();
        config(['app.url' => 'https://apps.segurexlatam.net']);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00', 'America/Bogota'));

        $this->sender = new FakePushSender;
        $this->app->instance(PushSender::class, $this->sender);

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);
        $this->derivados = Company::factory()->create(['name' => 'Soluciones y Derivados', 'short_name' => 'Derivados']);
    }

    private function device(User $user, string $name): PushSubscription
    {
        return PushSubscription::factory()->for($user)->endpoint("https://fcm.googleapis.com/fcm/send/{$name}")->create();
    }

    private function account(Company $company, string $name = 'Persona'): User
    {
        return User::factory()->microsoft($company)->create(['name' => $name]);
    }

    private function runCron(): void
    {
        $this->artisan('portal:send-announcement-pushes')->assertSuccessful();
    }

    public function test_it_sends_one_notice_per_device_of_the_people_who_see_it(): void
    {
        $ana = $this->account($this->segurex, 'Ana Pérez');
        $this->device($ana, 'ana-phone');
        $this->device($ana, 'ana-laptop');
        $this->device($this->account($this->derivados, 'Luis Gómez'), 'luis-phone');
        $emergency = User::factory()->emergency()->create();
        PushSubscription::factory()->for($emergency)->endpoint('https://fcm.googleapis.com/fcm/send/emergency')->create();

        $announcement = Announcement::factory()->forCompanies($this->segurex)->create(['push_phone' => true]);
        Announcement::factory()->create(['title' => 'Sin celular']);

        $this->runCron();

        $this->assertEqualsCanonicalizing(
            ['https://fcm.googleapis.com/fcm/send/ana-phone', 'https://fcm.googleapis.com/fcm/send/ana-laptop'],
            $this->sender->endpoints(),
        );
        $this->assertSame(2, PushDelivery::query()->where('announcement_id', $announcement->id)->where('status', PushDelivery::STATUS_SENT)->count());
        $this->assertNotNull($announcement->fresh()->push_finished_at);
    }

    public function test_a_device_never_gets_the_same_announcement_twice(): void
    {
        $ana = $this->account($this->segurex);
        $this->device($ana, 'ana-phone');
        $announcement = Announcement::factory()->create(['push_phone' => true]);

        $this->runCron();
        $this->runCron();
        $this->assertCount(1, $this->sender->sent);

        // Ticked again later: only the devices that did not get it yet.
        $announcement->update(['push_phone' => false]);
        $announcement->update(['push_phone' => true]);
        $this->assertNull($announcement->fresh()->push_finished_at);
        $this->device($ana, 'ana-new-phone');

        $this->runCron();

        $this->assertSame([
            'https://fcm.googleapis.com/fcm/send/ana-phone',
            'https://fcm.googleapis.com/fcm/send/ana-new-phone',
        ], $this->sender->endpoints());
    }

    public function test_a_device_subscribed_after_the_announcement_went_out_does_not_get_it(): void
    {
        $this->device($this->account($this->segurex), 'first');
        Announcement::factory()->create(['push_phone' => true]);

        $this->runCron();
        $this->device($this->account($this->segurex), 'later');
        $this->runCron();

        $this->assertSame(['https://fcm.googleapis.com/fcm/send/first'], $this->sender->endpoints());
    }

    public function test_only_published_announcements_with_the_box_ticked_go_out(): void
    {
        $this->device($this->account($this->segurex), 'phone');

        Announcement::factory()->withdrawn()->create(['push_phone' => true]);
        Announcement::factory()->expired()->create(['push_phone' => true]);
        Announcement::factory()->create(['push_phone' => false]);
        $scheduled = Announcement::factory()->scheduled()->create(['push_phone' => true]);

        $this->runCron();
        $this->assertSame([], $this->sender->sent);

        // When its window opens, the cron sends it.
        $this->travelTo($scheduled->starts_at->copy()->addMinute());
        $this->runCron();

        $this->assertCount(1, $this->sender->sent);
        $this->assertSame($scheduled->id, PushDelivery::query()->sole()->announcement_id);
    }

    public function test_a_withdrawn_announcement_stops_and_goes_on_when_republished(): void
    {
        config(['push.batch' => 1]);
        $this->device($this->account($this->segurex), 'one');
        $this->device($this->account($this->segurex), 'two');
        $announcement = Announcement::factory()->create(['push_phone' => true]);

        $this->runCron();
        $announcement->withdraw();
        $this->runCron();
        $this->assertCount(1, $this->sender->sent);

        $announcement->republish();
        $this->runCron();
        $this->assertCount(2, $this->sender->sent);
    }

    public function test_the_notice_has_the_title_a_short_text_the_link_and_the_company_icon_and_nothing_personal(): void
    {
        $ana = $this->account($this->segurex, 'Ana Pérez');
        $ana->forceFill(['email' => 'ana.perez@segurex.com'])->save();
        $this->device($ana, 'phone');
        $announcement = Announcement::factory()->create([
            'push_phone' => true,
            'title' => 'Cierre de oficinas',
            'body' => "Las oficinas cierran el viernes.\nTraigan su carné. ".str_repeat('Más detalles en el portal. ', 10),
        ]);

        $this->runCron();

        $payload = json_decode($this->sender->sent[0]->payload, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['title', 'body', 'url', 'icon', 'tag'], array_keys($payload));
        $this->assertSame('Cierre de oficinas', $payload['title']);
        $this->assertStringStartsWith('Las oficinas cierran el viernes. Traigan su carné.', $payload['body']);
        $this->assertLessThanOrEqual(140, mb_strlen($payload['body']));
        $this->assertSame('https://apps.segurexlatam.net/anuncios/'.$announcement->id, $payload['url']);
        $this->assertStringStartsWith('https://apps.segurexlatam.net/', $payload['icon']);
        $this->assertSame('anuncio-'.$announcement->id, $payload['tag']);

        $raw = $this->sender->sent[0]->payload;
        $this->assertStringNotContainsString('Ana', $raw);
        $this->assertStringNotContainsString('ana.perez', $raw);
    }

    public function test_devices_that_are_gone_are_deleted_and_failures_are_recorded_once(): void
    {
        $this->sender
            ->answer('https://fcm.googleapis.com/fcm/send/gone', PushSender::GONE)
            ->answer('https://fcm.googleapis.com/fcm/send/broken', PushSender::FAILED);
        $this->device($this->account($this->segurex), 'gone');
        $broken = $this->device($this->account($this->segurex), 'broken');
        $this->device($this->account($this->segurex), 'fine');
        $announcement = Announcement::factory()->create(['push_phone' => true]);

        $this->runCron();

        $this->assertEqualsCanonicalizing(
            ['https://fcm.googleapis.com/fcm/send/broken', 'https://fcm.googleapis.com/fcm/send/fine'],
            PushSubscription::query()->pluck('endpoint')->all(),
        );
        $statuses = PushDelivery::query()->where('announcement_id', $announcement->id)->pluck('status')->sort()->values()->all();
        $this->assertSame([PushDelivery::STATUS_FAILED, PushDelivery::STATUS_GONE, PushDelivery::STATUS_SENT], $statuses);
        $this->assertSame(PushDelivery::STATUS_FAILED, PushDelivery::query()->where('push_subscription_id', $broken->id)->sole()->status);

        // Not retried: a failure is recorded, never sent again.
        $this->runCron();
        $this->assertCount(3, $this->sender->sent);
    }

    public function test_it_sends_in_batches_across_runs(): void
    {
        config(['push.batch' => 2]);
        foreach (['a', 'b', 'c'] as $name) {
            $this->device($this->account($this->segurex), $name);
        }
        $announcement = Announcement::factory()->create(['push_phone' => true]);

        $this->runCron();
        $this->assertCount(2, $this->sender->sent);
        $this->assertNull($announcement->fresh()->push_finished_at);

        $this->runCron();
        $this->assertCount(3, $this->sender->sent);
        $this->assertNotNull($announcement->fresh()->push_finished_at);
    }

    public function test_it_reaches_exactly_the_accounts_that_see_it_on_the_home(): void
    {
        $andina = Company::factory()->create(['name' => 'Andina', 'is_active' => false]);
        $accounts = [
            $this->account($this->segurex, 'Segurex'),
            $this->account($this->derivados, 'Derivados'),
            $this->account($andina, 'Andina inactiva'),
            User::factory()->microsoft($this->segurex)->create(['is_guest' => true]),
            User::factory()->create(['company_id' => null]),
        ];
        foreach ($accounts as $i => $account) {
            $this->device($account, "device-{$i}");
        }

        // One at a time, so each run sends only that one.
        foreach ([
            fn () => Announcement::factory()->create(['push_phone' => true]),
            fn () => Announcement::factory()->forCompanies($this->derivados, $andina)->create(['push_phone' => true]),
        ] as $make) {
            $announcement = $make();
            $this->sender->sent = [];
            $this->runCron();

            $expected = collect($accounts)
                ->keys()
                ->filter(fn (int $i) => Announcement::query()->visibleTo($accounts[$i])->whereKey($announcement->id)->exists())
                ->map(fn (int $i) => "https://fcm.googleapis.com/fcm/send/device-{$i}")
                ->values()
                ->all();

            $this->assertNotEmpty($expected);
            $this->assertEqualsCanonicalizing($expected, $this->sender->endpoints(), 'Announcement '.$announcement->id);
        }
    }

    public function test_a_linked_account_gets_it_only_on_the_devices_of_the_account_that_sees_it(): void
    {
        $person = Person::query()->create();
        $inSegurex = $this->account($this->segurex, 'Ana');
        $inDerivados = $this->account($this->derivados, 'Ana');
        $inSegurex->forceFill(['person_id' => $person->id])->save();
        $inDerivados->forceFill(['person_id' => $person->id])->save();
        $this->device($inSegurex, 'segurex-phone');
        $this->device($inDerivados, 'derivados-phone');

        Announcement::factory()->forCompanies($this->derivados)->create(['push_phone' => true]);
        $this->runCron();

        $this->assertSame(['https://fcm.googleapis.com/fcm/send/derivados-phone'], $this->sender->endpoints());
    }

    public function test_without_the_vapid_settings_nothing_is_sent(): void
    {
        config(['push.vapid.private_key' => null]);
        $this->device($this->account($this->segurex), 'phone');
        $announcement = Announcement::factory()->create(['push_phone' => true]);

        $this->runCron();

        $this->assertSame([], $this->sender->sent);
        $this->assertSame(0, PushDelivery::query()->count());
        $this->assertNull($announcement->fresh()->push_finished_at);
    }

    public function test_it_runs_every_minute_without_overlapping(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'portal:send-announcement-pushes'));

        $this->assertNotNull($event, 'portal:send-announcement-pushes is not scheduled.');
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
