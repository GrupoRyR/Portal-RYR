<?php

namespace App\Models;

use Database\Factories\PushSubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A browser's push subscription for «Avisos en el celular»
 * (App\Push\PushSubscriptions): one device of one account. The endpoint is
 * unique through `endpoint_hash` ({@see hashOf()}).
 */
#[Fillable([
    'user_id',
    'endpoint',
    'endpoint_hash',
    'public_key',
    'auth_token',
    'content_encoding',
    'user_agent',
])]
#[Hidden(['public_key', 'auth_token'])]
class PushSubscription extends Model
{
    /** @use HasFactory<PushSubscriptionFactory> */
    use HasFactory;

    public const ENCODINGS = ['aes128gcm', 'aesgcm'];

    public static function hashOf(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
