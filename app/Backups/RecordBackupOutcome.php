<?php

namespace App\Backups;

use App\Models\BackupRun;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;

/**
 * Records how every backup ended, from the spatie/laravel-backup events,
 * for the «Respaldo diario» card. A backup running for a «Respaldar ahora»
 * request completes that request; any other run (nightly, or by hand on
 * the server) adds its own row. The reason of a failure is in the mail and
 * in storage/logs, not here.
 */
class RecordBackupOutcome
{
    public function handle(BackupWasSuccessful|BackupHasFailed $event): void
    {
        $status = $event instanceof BackupWasSuccessful ? BackupRun::STATUS_SUCCEEDED : BackupRun::STATUS_FAILED;

        $running = BackupRun::query()
            ->where('status', BackupRun::STATUS_RUNNING)
            ->latest('started_at')
            ->first();

        if ($running !== null) {
            $running->update(['status' => $status, 'finished_at' => now()]);

            return;
        }

        BackupRun::query()->create([
            'status' => $status,
            'started_at' => null,
            'finished_at' => now(),
        ]);
    }
}
