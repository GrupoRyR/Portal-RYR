<?php

namespace Tests\Feature\Console;

use App\Livewire\Admin\AnnouncementEditor;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\PushDelivery;
use App\Models\PushSubscription;
use App\Models\User;
use App\Push\AnnouncementPushes;
use App\Push\PushSender;
use App\Pwa\PwaIcons;
use App\Pwa\PwaIconSet;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Minishlink\WebPush\Encryption;
use RuntimeException;
use Tests\Feature\Push\FakePushSender;
use Tests\Feature\Push\PushTestKeys;
use Tests\TestCase;

/**
 * portal:send-announcement-pushes never loses a device to a failure of the
 * whole run (odd/tasks/avisos.md R2): it checks the sender before claiming
 * anything, stops when a whole chunk gets no HTTP answer, and one broken
 * announcement does not block the others. What was really tried is still
 * never sent twice.
 */
class AnnouncementPushesResilienceTest extends TestCase
{
    use PushTestKeys;
    use RefreshDatabase;

    private Company $segurex;

    private FakePushSender $sender;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurePush();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00', 'America/Bogota'));

        $this->sender = new FakePushSender;
        $this->app->instance(PushSender::class, $this->sender);
        $this->segurex = Company::factory()->create(['short_name' => 'Segurex']);
    }

    private function device(string $name, ?Company $company = null): PushSubscription
    {
        $user = User::factory()->microsoft($company ?? $this->segurex)->create(['last_login_at' => now()->subDay()]);

        return PushSubscription::factory()->for($user)->endpoint("https://fcm.googleapis.com/fcm/send/{$name}")->create();
    }

    private function runCron(): void
    {
        $this->artisan('portal:send-announcement-pushes')->assertSuccessful();
    }

    public function test_a_sender_that_cannot_start_claims_nothing(): void
    {
        $this->device('phone');
        $announcement = Announcement::factory()->create(['push_phone' => true]);
        $this->sender->startable = false;

        $this->runCron();

        $this->assertSame(0, PushDelivery::query()->count());
        $this->assertNull($announcement->fresh()->push_finished_at);

        // Fixed: the device still gets it.
        $this->sender->startable = true;
        $this->runCron();
        $this->assertSame(['https://fcm.googleapis.com/fcm/send/phone'], $this->sender->endpoints());
    }

    public function test_a_whole_chunk_without_an_http_answer_leaves_no_rows_and_stops_the_run(): void
    {
        $this->device('one');
        $this->device('two');
        $first = Announcement::factory()->create(['push_phone' => true, 'starts_at' => now()->subDays(2)]);
        $second = Announcement::factory()->create(['push_phone' => true]);
        $this->sender->everyone = PushSender::UNREACHABLE;

        $this->runCron();

        $this->assertSame(0, PushDelivery::query()->count());
        $this->assertNull($first->fresh()->push_finished_at);
        $this->assertNull($second->fresh()->push_finished_at);
        // The run stopped after the first chunk: the second announcement was not tried.
        $this->assertCount(2, $this->sender->sent);

        // The network is back: every device gets both.
        $this->sender->everyone = null;
        $this->sender->sent = [];
        $this->runCron();
        $this->assertCount(4, $this->sender->sent);
        $this->assertSame(4, PushDelivery::query()->where('status', PushDelivery::STATUS_SENT)->count());
    }

    public function test_one_unreachable_device_in_a_chunk_that_got_answers_is_failed_for_good(): void
    {
        $this->device('ok');
        $this->device('down');
        $this->sender->answer('https://fcm.googleapis.com/fcm/send/down', PushSender::UNREACHABLE);
        $announcement = Announcement::factory()->create(['push_phone' => true]);

        $this->runCron();
        $this->runCron();

        $this->assertCount(2, $this->sender->sent);
        $this->assertEqualsCanonicalizing(
            [PushDelivery::STATUS_SENT, PushDelivery::STATUS_FAILED],
            PushDelivery::query()->pluck('status')->all(),
        );
        $this->assertNotNull($announcement->fresh()->push_finished_at);
    }

    public function test_a_single_http_failure_stays_failed_and_is_never_sent_again(): void
    {
        $this->device('broken');
        $this->sender->answer('https://fcm.googleapis.com/fcm/send/broken', PushSender::FAILED);
        Announcement::factory()->create(['push_phone' => true]);

        $this->runCron();
        $this->runCron();

        $this->assertCount(1, $this->sender->sent);
        $this->assertSame(PushDelivery::STATUS_FAILED, PushDelivery::query()->sole()->status);
    }

    public function test_one_broken_announcement_does_not_block_the_others_and_claims_nothing(): void
    {
        $derivados = Company::factory()->create(['short_name' => 'Derivados']);
        $this->device('segurex');
        $this->device('derivados', $derivados);
        $broken = Announcement::factory()->forCompanies($derivados)->create(['push_phone' => true, 'starts_at' => now()->subDays(2)]);
        $fine = Announcement::factory()->forCompanies($this->segurex)->create(['push_phone' => true]);

        // The icon of Derivados cannot be made (built before anything is claimed).
        $this->app->instance(PwaIcons::class, new class($derivados->id) extends PwaIcons
        {
            public function __construct(private readonly int $brokenId) {}

            public function for(?Company $company): PwaIconSet
            {
                if ($company?->id === $this->brokenId) {
                    throw new RuntimeException('GD broke.');
                }

                return PwaIconSet::portal();
            }
        });

        $this->runCron();

        $this->assertSame(['https://fcm.googleapis.com/fcm/send/segurex'], $this->sender->endpoints());
        $this->assertSame(0, PushDelivery::query()->where('announcement_id', $broken->id)->count());
        $this->assertNull($broken->fresh()->push_finished_at);
        $this->assertNotNull($fine->fresh()->push_finished_at);
    }

    public function test_a_sender_that_throws_halfway_leaves_no_row_pending(): void
    {
        $this->device('phone');
        $announcement = Announcement::factory()->create(['push_phone' => true]);
        $this->sender->throwOnSend = true;

        $this->runCron();

        // It may have reached the device: failed, never sent again, never pending.
        $this->assertSame(PushDelivery::STATUS_FAILED, PushDelivery::query()->sole()->status);
        $this->assertNull($announcement->fresh()->push_finished_at);
    }

    public function test_a_device_claimed_by_an_overlapping_run_is_skipped(): void
    {
        $taken = $this->device('taken');
        $this->device('free');
        $announcement = Announcement::factory()->create(['push_phone' => true]);

        // Another run claims "taken" between this run's query and its claim.
        $once = false;
        PushDelivery::creating(function (PushDelivery $delivery) use (&$once, $taken) {
            if (! $once && $delivery->endpoint_hash === $taken->endpoint_hash) {
                $once = true;
                DB::table('push_deliveries')->insert([
                    'announcement_id' => $delivery->announcement_id,
                    'push_subscription_id' => $taken->id,
                    'endpoint_hash' => $taken->endpoint_hash,
                    'status' => PushDelivery::STATUS_SENT,
                    'created_at' => now(),
                ]);
            }
        });

        $this->runCron();

        $this->assertTrue($once);
        $this->assertSame(['https://fcm.googleapis.com/fcm/send/free'], $this->sender->endpoints());
        $this->assertSame(2, PushDelivery::query()->where('announcement_id', $announcement->id)->count());
    }

    public function test_no_notice_is_taken_once_the_time_budget_is_spent(): void
    {
        config(['push.run_seconds' => 0]);
        $this->device('phone');
        $announcement = Announcement::factory()->create(['push_phone' => true]);

        $this->runCron();

        $this->assertSame([], $this->sender->sent);
        $this->assertSame(0, PushDelivery::query()->count());
        $this->assertNull($announcement->fresh()->push_finished_at);
    }

    public function test_finishing_does_not_undo_a_box_ticked_again_during_the_run(): void
    {
        $this->device('phone');
        $announcement = Announcement::factory()->create(['push_phone' => true]);

        // While the run sends, an editor unticks and ticks the box again.
        $this->sender->answer('https://fcm.googleapis.com/fcm/send/phone', PushSender::SENT);
        PushDelivery::created(function () use ($announcement) {
            $this->travel(1)->seconds();
            $fresh = $announcement->fresh();
            $fresh->update(['push_phone' => false]);
            $fresh->update(['push_phone' => true]);
        });

        $this->runCron();

        $this->assertNull($announcement->fresh()->push_finished_at);
    }

    public function test_changing_the_audience_while_ticked_lets_the_new_companies_get_it(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('portal_admin');
        $derivados = Company::factory()->create(['short_name' => 'Derivados']);
        $announcement = Announcement::factory()->forCompanies($this->segurex)->create(['push_phone' => true]);
        $announcement->forceFill(['push_finished_at' => now()])->save();

        Livewire::actingAs($admin)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->set('companyIds', [(string) $this->segurex->id, (string) $derivados->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($announcement->fresh()->push_finished_at);

        // Other edits keep it finished.
        $announcement->fresh()->forceFill(['push_finished_at' => now()])->save();
        Livewire::actingAs($admin)->test(AnnouncementEditor::class, ['announcement' => $announcement->fresh()])
            ->set('title', 'Otro título')
            ->call('save');
        $this->assertNotNull($announcement->fresh()->push_finished_at);
    }

    public function test_the_largest_possible_notice_fits_the_push_payload_limit(): void
    {
        $announcement = Announcement::factory()->create([
            'title' => str_repeat('😀', Announcement::TITLE_MAX),
            'body' => str_repeat('😀"\\', intdiv(Announcement::BODY_MAX, 3)),
        ]);

        $payload = app(AnnouncementPushes::class)->payload($announcement, $this->segurex->id);

        $this->assertLessThanOrEqual(AnnouncementPushes::MAX_PAYLOAD_BYTES, strlen($payload));
        $this->assertLessThan(Encryption::MAX_PAYLOAD_LENGTH, AnnouncementPushes::MAX_PAYLOAD_BYTES);
        // Str::limit counts display width: an emoji takes two.
        $this->assertLessThanOrEqual(AnnouncementPushes::BODY_LENGTH, mb_strwidth(json_decode($payload, true)['body']));
    }
}
