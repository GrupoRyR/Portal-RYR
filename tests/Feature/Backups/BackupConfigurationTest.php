<?php

namespace Tests\Feature\Backups;

use App\Backups\BackupNotifiable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Events\CleanupWasSuccessful;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;
use Tests\TestCase;

class BackupConfigurationTest extends TestCase
{
    // --- Destination --------------------------------------------------------

    public function test_backups_go_to_a_private_disk_outside_the_web_root(): void
    {
        $root = str_replace('\\', '/', config('filesystems.disks.backups.root'));

        $this->assertSame('local', config('filesystems.disks.backups.driver'));
        $this->assertSame(str_replace('\\', '/', storage_path('app/private/backups')), $root);
        $this->assertStringNotContainsString('app/public', $root);
        $this->assertStringStartsNotWith(str_replace('\\', '/', public_path()), $root);
        $this->assertFalse(config('filesystems.disks.backups.serve', false));
        $this->assertSame(['backups'], config('backup.backup.destination.disks'));
        $this->assertSame('portal-ryr', config('backup.backup.name'));
    }

    public function test_the_database_and_the_public_files_are_backed_up(): void
    {
        $this->assertSame([config('database.default')], config('backup.backup.source.databases'));
        $this->assertSame([storage_path('app/public')], config('backup.backup.source.files.include'));
        $this->assertSame(base_path(), config('backup.backup.source.files.relative_path'));
        $this->assertFalse(config('backup.backup.source.files.follow_links'));
    }

    public function test_mysqldump_path_is_configurable(): void
    {
        foreach (['mysql', 'mariadb'] as $connection) {
            $this->assertSame('/usr/bin', config("database.connections.{$connection}.dump.dump_binary_path"));
            $this->assertTrue(config("database.connections.{$connection}.dump.use_single_transaction"));
        }
    }

    // --- Retention ----------------------------------------------------------

    public function test_retention_keeps_seven_daily_and_four_weekly_copies_within_a_size_cap(): void
    {
        $strategy = config('backup.cleanup.default_strategy');

        $this->assertSame(7, $strategy['keep_all_backups_for_days']);
        $this->assertSame(0, $strategy['keep_daily_backups_for_days']);
        $this->assertSame(4, $strategy['keep_weekly_backups_for_weeks']);
        $this->assertSame(0, $strategy['keep_monthly_backups_for_months']);
        $this->assertSame(0, $strategy['keep_yearly_backups_for_years']);
        $this->assertSame(2000, $strategy['delete_oldest_backups_when_using_more_megabytes_than']);

        $monitor = config('backup.monitor_backups.0');
        $this->assertSame('portal-ryr', $monitor['name']);
        $this->assertSame(['backups'], $monitor['disks']);
        $this->assertSame(1, $monitor['health_checks'][MaximumAgeInDays::class]);
        $this->assertSame(2500, $monitor['health_checks'][MaximumStorageInMegabytes::class]);
    }

    // --- Alerts -------------------------------------------------------------

    public function test_only_problems_are_mailed_and_nothing_goes_to_slack_or_discord(): void
    {
        $this->assertSame([
            BackupHasFailedNotification::class => ['mail'],
            UnhealthyBackupWasFoundNotification::class => ['mail'],
            CleanupHasFailedNotification::class => ['mail'],
            BackupWasSuccessfulNotification::class => [],
            HealthyBackupWasFoundNotification::class => [],
            CleanupWasSuccessfulNotification::class => [],
        ], config('backup.notifications.notifications'));

        $this->assertSame(BackupNotifiable::class, config('backup.notifications.notifiable'));
        $this->assertSame('', config('backup.notifications.slack.webhook_url'));
        $this->assertSame('', config('backup.notifications.discord.webhook_url'));
    }

    public function test_alerts_go_to_the_super_admin_emails(): void
    {
        config(['portal.super_admin_emails' => ['jhon@segurex.com', 'ti@segurex.com']]);

        $this->assertSame(['jhon@segurex.com', 'ti@segurex.com'], (new BackupNotifiable)->routeNotificationForMail());

        $failed = new BackupHasFailedNotification(new BackupHasFailed(new \Exception('mysqldump: not found')));
        $this->assertSame(['mail'], $failed->via());

        $this->assertSame([], (new BackupWasSuccessfulNotification(new BackupWasSuccessful('backups', 'portal-ryr')))->via());
        $this->assertSame([], (new CleanupWasSuccessfulNotification(new CleanupWasSuccessful('backups', 'portal-ryr')))->via());
    }

    // --- Schedule -----------------------------------------------------------

    private function scheduled(string $command): ?Event
    {
        return collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, $command));
    }

    public function test_clean_run_and_monitor_are_scheduled_at_night_in_bogota_time(): void
    {
        $expected = [
            'backup:clean' => '0 2 * * *',
            'backup:run' => '30 2 * * *',
            'backup:monitor' => '0 8 * * *',
            'portal:run-requested-backup' => '* * * * *',
        ];

        foreach ($expected as $command => $expression) {
            $event = $this->scheduled($command);

            $this->assertNotNull($event, "{$command} is not scheduled.");
            $this->assertSame($expression, $event->expression, $command);
            $this->assertSame('America/Bogota', $event->timezone, $command);
            $this->assertTrue($event->withoutOverlapping, $command);
        }

        $this->assertStringContainsString('--isolated', (string) $this->scheduled('backup:run')->command);
    }
}
