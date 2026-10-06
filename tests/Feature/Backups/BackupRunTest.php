<?php

namespace Tests\Feature\Backups;

use App\Models\BackupRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Tasks\Backup\DbDumperFactory;
use Spatie\DbDumper\Databases\Sqlite;
use SQLite3;
use Tests\Support\PhpSqliteDumper;
use Tests\TestCase;
use ZipArchive;

/**
 * Real `backup:run` against an SQLite file (dumped in PHP, see
 * PhpSqliteDumper), and the scheduled task that runs the backups asked
 * for with «Respaldar ahora».
 */
class BackupRunTest extends TestCase
{
    use RefreshDatabase;

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'portal-backup-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workDir.'/tmp');

        // A real SQLite file with a row in it stands in for the MySQL database.
        $database = $this->workDir.'/portal.sqlite';
        $sqlite = new SQLite3($database);
        $sqlite->exec("CREATE TABLE companies (id INTEGER PRIMARY KEY, name TEXT); INSERT INTO companies VALUES (1, 'Segurex Latam');");
        $sqlite->close();

        Storage::fake('backups');
        Storage::fake('public');
        Storage::disk('public')->put('brand/segurex-logo.svg', '<svg/>');

        config([
            'database.connections.backup_source' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => ''],
            'backup.backup.source.databases' => ['backup_source'],
            'backup.backup.source.files.include' => [Storage::disk('public')->path('')],
            'backup.backup.temporary_directory' => $this->workDir.'/tmp',
        ]);
        $this->app->forgetScopedInstances();

        DbDumperFactory::extend('sqlite', fn () => new PhpSqliteDumper);
    }

    protected function tearDown(): void
    {
        DbDumperFactory::extend('sqlite', fn () => new Sqlite);
        File::deleteDirectory($this->workDir);

        parent::tearDown();
    }

    /**
     * @return list<string>
     */
    private function backups(): array
    {
        return array_values(array_filter(
            Storage::disk('backups')->allFiles('portal-ryr'),
            fn (string $path) => str_ends_with($path, '.zip'),
        ));
    }

    private function requestedBy(): User
    {
        return User::factory()->create(['name' => 'Jhon Castro']);
    }

    // --- backup:run ---------------------------------------------------------

    public function test_backup_run_stores_a_zip_with_the_database_dump_and_the_public_files(): void
    {
        $this->assertSame(0, Artisan::call('backup:run'));

        $backups = $this->backups();
        $this->assertCount(1, $backups);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('backups')->path($backups[0])));

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[$i] = str_replace('\\', '/', $zip->getNameIndex($i));
        }
        $listing = implode(', ', $names);

        $dump = collect($names)->search(fn (string $name) => str_starts_with($name, 'db-dumps/') && str_ends_with($name, '.sql'));
        $this->assertNotFalse($dump, "The zip has no database dump: {$listing}");
        $this->assertStringContainsString("INSERT INTO \"companies\" VALUES(1,'Segurex Latam');", (string) $zip->getFromIndex($dump));

        $logo = collect($names)->search(fn (string $name) => str_ends_with($name, 'disks/public/brand/segurex-logo.svg'));
        $this->assertNotFalse($logo, "The zip has no public files: {$listing}");
        $this->assertSame('<svg/>', $zip->getFromIndex($logo));
        $zip->close();

        // The package's success event is recorded for the card.
        $run = BackupRun::query()->sole();
        $this->assertSame(BackupRun::STATUS_SUCCEEDED, $run->status);
        $this->assertNull($run->requested_by);
        $this->assertNotNull($run->finished_at);
    }

    public function test_a_failed_backup_is_recorded(): void
    {
        config(['backup.backup.source.databases' => ['missing_connection']]);
        $this->app->forgetScopedInstances();

        $this->assertSame(1, Artisan::call('backup:run'));

        $this->assertSame([], $this->backups());
        $this->assertSame(BackupRun::STATUS_FAILED, BackupRun::query()->sole()->status);
    }

    public function test_package_events_complete_the_running_request_instead_of_adding_a_row(): void
    {
        $running = BackupRun::query()->create([
            'status' => BackupRun::STATUS_RUNNING,
            'requested_by' => $this->requestedBy()->id,
            'requested_at' => now(),
            'started_at' => now(),
        ]);

        event(new BackupWasSuccessful('backups', 'portal-ryr'));

        $this->assertSame(1, BackupRun::query()->count());
        $this->assertSame(BackupRun::STATUS_SUCCEEDED, $running->fresh()->status);
        $this->assertNotNull($running->fresh()->finished_at);

        event(new BackupHasFailed(new \Exception('mysqldump: command not found')));

        $this->assertSame(2, BackupRun::query()->count());
        $this->assertSame(BackupRun::STATUS_FAILED, BackupRun::query()->latest('id')->first()->status);
    }

    // --- Requested backups ----------------------------------------------------

    public function test_the_scheduler_runs_a_requested_backup_and_clears_the_request(): void
    {
        $user = $this->requestedBy();
        $request = BackupRun::query()->create([
            'status' => BackupRun::STATUS_PENDING,
            'requested_by' => $user->id,
            'requested_at' => now(),
        ]);

        $this->assertSame(0, Artisan::call('portal:run-requested-backup'));

        $this->assertCount(1, $this->backups());
        $request->refresh();
        $this->assertSame(BackupRun::STATUS_SUCCEEDED, $request->status);
        $this->assertNotNull($request->started_at);
        $this->assertNotNull($request->finished_at);
        $this->assertSame(1, BackupRun::query()->count());

        // Nothing pending: the next minutes do nothing.
        Artisan::call('portal:run-requested-backup');
        $this->assertCount(1, $this->backups());
    }

    public function test_nothing_runs_without_a_request(): void
    {
        $this->assertSame(0, Artisan::call('portal:run-requested-backup'));

        $this->assertSame([], $this->backups());
        $this->assertSame(0, BackupRun::query()->count());
    }

    public function test_a_requested_backup_that_fails_is_marked_as_failed(): void
    {
        config(['backup.backup.source.databases' => ['missing_connection']]);
        $this->app->forgetScopedInstances();

        $request = BackupRun::query()->create([
            'status' => BackupRun::STATUS_PENDING,
            'requested_by' => $this->requestedBy()->id,
            'requested_at' => now(),
        ]);

        Artisan::call('portal:run-requested-backup');

        $this->assertSame(BackupRun::STATUS_FAILED, $request->fresh()->status);
        $this->assertSame(1, BackupRun::query()->count());
    }

    public function test_a_request_waits_while_another_backup_is_running(): void
    {
        $request = BackupRun::query()->create([
            'status' => BackupRun::STATUS_PENDING,
            'requested_by' => $this->requestedBy()->id,
            'requested_at' => now(),
        ]);

        // The nightly backup holds the isolation lock of backup:run.
        $lock = Cache::lock('framework'.DIRECTORY_SEPARATOR.'command-backup:run', 3600);
        $this->assertTrue($lock->get());

        Artisan::call('portal:run-requested-backup');

        $this->assertSame(BackupRun::STATUS_PENDING, $request->fresh()->status);
        $this->assertNull($request->fresh()->started_at);
        $this->assertSame([], $this->backups());

        $lock->release();
    }

    public function test_a_backup_stuck_as_running_is_given_up_after_two_hours(): void
    {
        $stuck = BackupRun::query()->create([
            'status' => BackupRun::STATUS_RUNNING,
            'requested_by' => $this->requestedBy()->id,
            'requested_at' => CarbonImmutable::now()->subHours(3),
            'started_at' => CarbonImmutable::now()->subHours(3),
        ]);

        Artisan::call('portal:run-requested-backup');

        $this->assertSame(BackupRun::STATUS_FAILED, $stuck->fresh()->status);
        $this->assertNotNull($stuck->fresh()->finished_at);
    }
}
