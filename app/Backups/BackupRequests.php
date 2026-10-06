<?php

namespace App\Backups;

use App\Models\BackupRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * «Respaldar ahora» (Administración → Configuración). A dump can take
 * minutes, longer than a web request may last on shared hosting, so the
 * button only leaves a pending request; the scheduler runs it the next
 * minute (App\Console\Commands\RunRequestedBackup). One request at a time,
 * a few per hour, each one written to the activity log.
 */
final class BackupRequests
{
    /** Activity log name of the requests, shown in Auditoría. */
    public const AUDIT_LOG = 'backups';

    public const RATE_LIMIT_KEY = 'backups:request';

    public const MAX_REQUESTS_PER_HOUR = 3;

    /**
     * Why a backup cannot be requested now, or null.
     */
    public function refusal(): ?string
    {
        if (BackupRun::query()->unfinished()->exists()) {
            return 'Ya hay un respaldo programado o en curso. Espera a que termine.';
        }

        if (RateLimiter::tooManyAttempts(self::RATE_LIMIT_KEY, self::MAX_REQUESTS_PER_HOUR)) {
            $minutes = (int) ceil(RateLimiter::availableIn(self::RATE_LIMIT_KEY) / 60);

            return 'Ya se pidieron '.self::MAX_REQUESTS_PER_HOUR." respaldos en la última hora. Intenta de nuevo en {$minutes} min.";
        }

        return null;
    }

    /**
     * Leaves a request for the scheduler. Call refusal() first.
     */
    public function request(User $user): BackupRun
    {
        RateLimiter::hit(self::RATE_LIMIT_KEY, 3600);

        return DB::transaction(function () use ($user) {
            $run = BackupRun::query()->create([
                'status' => BackupRun::STATUS_PENDING,
                'requested_by' => $user->id,
                'requested_at' => now(),
            ]);

            activity(self::AUDIT_LOG)
                ->event('backup.requested')
                ->causedBy($user)
                ->log('backup.requested');

            return $run;
        });
    }
}
