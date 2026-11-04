<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The email of an event to one company's all-staff list
 * (App\Calendar\EventListEmails), with the same life as an announcement's
 * (App\Models\AnnouncementListEmail): unique per event and company,
 * written as SENDING before Microsoft Graph is called, and only
 * «Reintentar» sets a FAILED row back to PENDING.
 *
 * @property int $id
 * @property int $event_id
 * @property int $company_id
 * @property string $status
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $sent_at
 * @property Carbon|null $retried_at
 * @property Carbon $updated_at
 */
#[Fillable(['event_id', 'company_id', 'status', 'attempts', 'last_error', 'sent_at', 'retried_at'])]
class EventListEmail extends Model
{
    public const STATUS_PENDING = AnnouncementListEmail::STATUS_PENDING;

    public const STATUS_SENDING = AnnouncementListEmail::STATUS_SENDING;

    public const STATUS_SENT = AnnouncementListEmail::STATUS_SENT;

    public const STATUS_FAILED = AnnouncementListEmail::STATUS_FAILED;

    public const ERROR_INTERRUPTED = AnnouncementListEmail::ERROR_INTERRUPTED;

    public const ERROR_DOMAIN_MISMATCH = AnnouncementListEmail::ERROR_DOMAIN_MISMATCH;

    public const RETRY_MINUTES = AnnouncementListEmail::RETRY_MINUTES;

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'retried_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * When «Reintentar» works again; null when it may be used now.
     */
    public function nextRetryAt(): ?Carbon
    {
        $next = $this->retried_at?->copy()->addMinutes(self::RETRY_MINUTES);

        return $next !== null && $next->isFuture() ? $next : null;
    }
}
