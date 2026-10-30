<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * «Enviar recordatorio» on a mandatory announcement
 * (App\Announcements\AnnouncementReminders): at most one every
 * INTERVAL_HOURS per announcement. The request only records it; the cron
 * (portal:send-announcement-reminders) mails the people who are still
 * missing, one delivery per person, and then marks it finished.
 *
 * @property int $announcement_id
 * @property int|null $requested_by
 * @property Carbon $requested_at
 * @property int $missing_count
 * @property Carbon|null $finished_at
 */
#[Fillable(['announcement_id', 'requested_by', 'requested_at', 'missing_count', 'finished_at'])]
class AnnouncementReminder extends Model
{
    /** At most one reminder per announcement in this many hours. */
    public const INTERVAL_HOURS = 24;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'finished_at' => 'datetime',
            'missing_count' => 'integer',
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
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return HasMany<AnnouncementReminderDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(AnnouncementReminderDelivery::class);
    }

    /**
     * When the next reminder of the same announcement may be requested.
     */
    public function nextAllowedAt(): Carbon
    {
        return $this->requested_at->copy()->addHours(self::INTERVAL_HOURS);
    }

    /**
     * Still being sent by the cron.
     *
     * @param  Builder<self>  $query
     */
    public function scopeUnfinished(Builder $query): void
    {
        $query->whereNull('finished_at');
    }
}
