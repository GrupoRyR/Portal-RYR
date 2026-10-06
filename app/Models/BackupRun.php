<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One backup of the portal (spatie/laravel-backup): a finished run with its
 * outcome, recorded from the package events (App\Backups\RecordBackupOutcome),
 * or a «Respaldar ahora» request that the scheduler runs the next minute
 * (App\Console\Commands\RunRequestedBackup). Nightly runs have no requester.
 *
 * @property string $status
 * @property int|null $requested_by
 * @property Carbon|null $requested_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
#[Fillable(['status', 'requested_by', 'requested_at', 'started_at', 'finished_at'])]
class BackupRun extends Model
{
    /** Requested with «Respaldar ahora», waiting for the scheduler. */
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * A request waiting for the scheduler or a backup in progress.
     *
     * @param  Builder<self>  $query
     */
    public function scopeUnfinished(Builder $query): void
    {
        $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_RUNNING]);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeFinished(Builder $query): void
    {
        $query->whereIn('status', [self::STATUS_SUCCEEDED, self::STATUS_FAILED]);
    }

    public function succeeded(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }
}
