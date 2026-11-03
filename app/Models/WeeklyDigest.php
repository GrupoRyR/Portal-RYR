<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The Monday digest of one company for one week (App\Digest\WeeklyDigests):
 * unique per company and week, written before Microsoft Graph is called,
 * so a list never gets the same week twice. Nothing sends a row again: a
 * failed or interrupted week stays as it is and the next one comes the
 * following Monday.
 *
 * @property int $company_id
 * @property Carbon $week_start The Monday of the week, in Bogotá.
 * @property string $status
 * @property string|null $error_code
 * @property Carbon|null $attempted_at
 * @property Carbon|null $sent_at
 * @property Carbon $updated_at
 */
#[Fillable(['company_id', 'week_start', 'status', 'error_code', 'attempted_at', 'sent_at'])]
class WeeklyDigest extends Model
{
    /** Claimed by a run of the cron, Microsoft's answer not recorded yet. */
    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    /** Not sent; `error_code` says why. Never sent again. */
    public const STATUS_FAILED = 'failed';

    /** Nothing to tell that week: not sent. */
    public const STATUS_EMPTY = 'empty';

    /**
     * A run died while sending: the email may or may not have left, so it
     * is never sent again.
     */
    public const ERROR_INTERRUPTED = AnnouncementListEmail::ERROR_INTERRUPTED;

    /** The sender or the list is not at one of the company's domains. */
    public const ERROR_DOMAIN_MISMATCH = AnnouncementListEmail::ERROR_DOMAIN_MISMATCH;

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'attempted_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * Stored as «2026-11-09» whatever it is given as, so the unique pair
     * and the lookups by week match on every database.
     */
    protected function weekStart(): Attribute
    {
        return Attribute::make(set: fn (mixed $value) => CarbonImmutable::parse($value)->toDateString());
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
