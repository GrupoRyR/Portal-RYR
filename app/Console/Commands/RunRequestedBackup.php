<?php

namespace App\Console\Commands;

use App\Models\BackupRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Runs the backup asked for with «Respaldar ahora» (App\Backups\BackupRequests).
 * Scheduled every minute: without a request it does nothing. The backup
 * itself is `backup:run --isolated`, so it never overlaps the nightly one;
 * while that one runs, the request simply waits for the next minute.
 */
class RunRequestedBackup extends Command
{
    /** Exit code of `backup:run --isolated` when another backup holds the lock. */
    public const BUSY = 75;

    /** A backup still «running» after this long died with its process. */
    public const STALE_AFTER_HOURS = 2;

    protected $signature = 'portal:run-requested-backup';

    protected $description = 'Run the backup requested from Configuración, if any';

    public function handle(): int
    {
        BackupRun::query()
            ->where('status', BackupRun::STATUS_RUNNING)
            ->where('started_at', '<', now()->subHours(self::STALE_AFTER_HOURS))
            ->update(['status' => BackupRun::STATUS_FAILED, 'finished_at' => now()]);

        $request = BackupRun::query()
            ->where('status', BackupRun::STATUS_PENDING)
            ->oldest('requested_at')
            ->first();

        if ($request === null) {
            return self::SUCCESS;
        }

        $request->update(['status' => BackupRun::STATUS_RUNNING, 'started_at' => now()]);

        $exitCode = Artisan::call('backup:run', ['--isolated' => self::BUSY]);

        if ($exitCode === self::BUSY) {
            $request->update(['status' => BackupRun::STATUS_PENDING, 'started_at' => null]);
            $this->info('Another backup is running; the request waits for the next minute.');

            return self::SUCCESS;
        }

        // The package events normally completed the request already
        // (App\Backups\RecordBackupOutcome); this covers a run that ended
        // without firing them.
        $request->refresh();
        if ($request->status === BackupRun::STATUS_RUNNING) {
            $request->update([
                'status' => $exitCode === self::SUCCESS ? BackupRun::STATUS_SUCCEEDED : BackupRun::STATUS_FAILED,
                'finished_at' => now(),
            ]);
        }

        $this->info($request->succeeded() ? 'Requested backup finished.' : 'Requested backup failed.');

        return $request->succeeded() ? self::SUCCESS : self::FAILURE;
    }
}
