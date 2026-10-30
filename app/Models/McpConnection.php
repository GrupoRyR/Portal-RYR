<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

/**
 * A Claude connection (odd/tasks/conector-mcp.md): one OAuth client of
 * Passport and the account that approved it, which Passport's tokens are
 * issued to. The accounts Claude may read for are listed apart
 * ({@see McpConnectionAccount}) and checked on every call
 * (App\Mcp\ConnectionAccounts). Written only by App\Mcp\Connections.
 *
 * @property string $oauth_client_id
 * @property int $user_id
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 */
#[Fillable(['oauth_client_id', 'user_id'])]
class McpConnection extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<McpConnectionAccount, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(McpConnectionAccount::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    /**
     * Ends the connection: it stops answering at once, and every access
     * and refresh token of its client for its account is revoked.
     */
    public function revoke(): void
    {
        DB::transaction(function () {
            $tokens = Passport::token()->newQuery()
                ->where('client_id', $this->oauth_client_id)
                ->where('user_id', $this->user_id);

            Passport::refreshToken()->newQuery()
                ->whereIn('access_token_id', (clone $tokens)->select('id'))
                ->update(['revoked' => true]);
            $tokens->update(['revoked' => true]);

            $this->forceFill(['revoked_at' => now()])->save();
        });
    }
}
