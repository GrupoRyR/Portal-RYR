<?php

namespace App\Models;

use Database\Factories\WorkItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The portal's copy of a task or an approval of the tasks app
 * (docs/contrato-tareas-aprobaciones.md). The app is the source of truth
 * and writes it through the API (App\Work\WorkItemWriter); `external_id`
 * is unique within its integration only.
 *
 * It belongs to one Microsoft account, `{assignee_tenant_id,
 * assignee_oid}`, never to an email nor a `person_id`, so it shows only in
 * the company of that account (§4). A deleted item keeps only its
 * `version` for RETENTION_DAYS (`deleted_at`), so an old call cannot bring
 * it back (§3.3).
 *
 * Not fillable: only App\Work\WorkItemWriter writes it.
 */
class WorkItem extends Model
{
    /** @use HasFactory<WorkItemFactory> */
    use HasFactory, MassPrunable, SoftDeletes;

    public const KIND_TASK = 'task';

    public const KIND_APPROVAL = 'approval';

    public const KINDS = [self::KIND_TASK, self::KIND_APPROVAL];

    public const STATUS_PENDING = 'pending';

    public const STATUS_DONE = 'done';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_DONE, self::STATUS_CANCELLED];

    /** `^[A-Za-z0-9][A-Za-z0-9._:-]{0,189}$`, without the anchors (route constraint). */
    public const EXTERNAL_ID_PATTERN = '[A-Za-z0-9][A-Za-z0-9._:-]{0,189}';

    public const MAX_TITLE = 140;

    public const MAX_URL = 2048;

    /** Largest integer a JSON number keeps exactly (2^53 − 1). */
    public const MAX_VERSION = 9007199254740991;

    public const MAX_BODY_BYTES = 8192;

    public const MAX_PENDING_PER_PERSON = 500;

    /** Days a deletion trace, a done or a cancelled item is kept. */
    public const RETENTION_DAYS = 30;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'completable_from_portal' => 'boolean',
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Integration, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /**
     * What the home may show to the signed-in account (§4): its own
     * pending items, from integrations that are not revoked and still have
     * `work:write` and a catalog app. Items of an integration that lost
     * either are kept, hidden, and show again once both are back. Accounts
     * without a Microsoft identity (emergency) see nothing.
     *
     * @param  Builder<WorkItem>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->tenant_id === null || $user->azure_oid === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('assignee_tenant_id', strtolower($user->tenant_id))
            ->where('assignee_oid', strtolower($user->azure_oid))
            ->where('status', self::STATUS_PENDING)
            ->whereHas('integration', fn (Builder $integration) => $integration
                ->whereNull('revoked_at')
                ->whereNotNull('app_id')
                ->whereJsonContains('abilities', Integration::WORK_WRITE));
    }

    /**
     * Deletion traces, done and cancelled items older than RETENTION_DAYS
     * (§3.6), for `php artisan model:prune`.
     *
     * @return Builder<WorkItem>
     */
    public function prunable(): Builder
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);

        return static::withTrashed()->where(function (Builder $query) use ($cutoff) {
            $query->where('deleted_at', '<=', $cutoff)
                ->orWhere(fn (Builder $finished) => $finished
                    ->whereIn('status', [self::STATUS_DONE, self::STATUS_CANCELLED])
                    ->where('updated_at', '<=', $cutoff));
        });
    }
}
