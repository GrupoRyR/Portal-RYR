<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One account a Claude connection may read for, confirmed with Microsoft
 * when connecting (`confirmed_at`). Being listed here is necessary, never
 * enough: App\Mcp\ConnectionAccounts checks it again on every call.
 *
 * @property int $mcp_connection_id
 * @property int $user_id
 * @property Carbon $confirmed_at
 */
#[Fillable(['user_id', 'confirmed_at'])]
class McpConnectionAccount extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<McpConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(McpConnection::class, 'mcp_connection_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
