<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One account's confirmation that it read a mandatory announcement
 * (Announcement::markReadBy()). One row per announcement and account; a
 * person has read it when any of their linked accounts has a row
 * (Announcement::isReadByPersonOf()).
 *
 * @property Carbon $read_at
 */
#[Fillable(['announcement_id', 'user_id', 'read_at'])]
class AnnouncementRead extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
