<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The email of an announcement to one company's all-staff list
 * (App\Announcements\AnnouncementListEmails): unique per announcement and
 * company, written as SENDING before Microsoft Graph is called, so a list
 * never gets the same announcement twice on its own. Only «Reintentar»
 * (a person, after reading the error) sets a FAILED row back to PENDING.
 *
 * @property int $announcement_id
 * @property int $company_id
 * @property string $status
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $sent_at
 * @property Carbon|null $retried_at
 * @property Carbon $updated_at
 */
#[Fillable(['announcement_id', 'company_id', 'status', 'attempts', 'last_error', 'sent_at', 'retried_at'])]
class AnnouncementListEmail extends Model
{
    /** Asked for again with «Reintentar»; the next run of the cron sends it. */
    public const STATUS_PENDING = 'pending';

    /** Claimed by a run of the cron, Microsoft's answer not recorded yet. */
    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    /** Not sent; `last_error` says why. Never retried on its own. */
    public const STATUS_FAILED = 'failed';

    /**
     * A run died while sending: the email may or may not have left, so it
     * is not sent again unless a person retries it.
     */
    public const ERROR_INTERRUPTED = 'Interrupted';

    /** The sender or the list is not at one of the company's domains. */
    public const ERROR_DOMAIN_MISMATCH = 'DomainMismatch';

    /** «Reintentar» works once every this many minutes per row. */
    public const RETRY_MINUTES = 10;

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'retried_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Announcement, $this>
     */
    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * When «Reintentar» works again; null when it may be used now (as far
     * as the limit goes).
     */
    public function nextRetryAt(): ?Carbon
    {
        $next = $this->retried_at?->copy()->addMinutes(self::RETRY_MINUTES);

        return $next !== null && $next->isFuture() ? $next : null;
    }
}
