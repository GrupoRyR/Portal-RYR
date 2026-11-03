<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A phone notice of an announcement to one device (App\Push\AnnouncementPushes),
 * recorded before it is sent; never sent again, whatever its status.
 */
#[Fillable([
    'announcement_id',
    'push_subscription_id',
    'endpoint_hash',
    'status',
    'created_at',
])]
class PushDelivery extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** The push service said the subscription no longer exists (404/410). */
    public const STATUS_GONE = 'gone';

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Announcement, $this>
     */
    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    /**
     * @return BelongsTo<PushSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(PushSubscription::class, 'push_subscription_id');
    }
}
