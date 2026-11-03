<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person mailed for a reminder (App\Announcements\AnnouncementReminders):
 * unique per reminder and person, written before the email goes out, so a
 * person never gets two emails for the same reminder, even across batches
 * or a run that died halfway. A row left PENDING is never retried.
 *
 * @property int $announcement_reminder_id
 * @property int $person_key
 * @property int|null $user_id
 * @property string $status
 * @property Carbon $created_at
 */
#[Fillable(['announcement_reminder_id', 'person_key', 'user_id', 'status', 'created_at'])]
class AnnouncementReminderDelivery extends Model
{
    /** Recorded, the email not confirmed yet. */
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    /** The mailer failed; logged and not retried. */
    public const STATUS_FAILED = 'failed';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'person_key' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AnnouncementReminder, $this>
     */
    public function reminder(): BelongsTo
    {
        return $this->belongsTo(AnnouncementReminder::class, 'announcement_reminder_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
