<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Client;
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
 * @property string|null $active_key
 */
#[Fillable(['oauth_client_id', 'user_id'])]
class McpConnection extends Model
{
    public const UPDATED_AT = null;

    /**
     * A new connection is active: it takes the unique `active_key` of its
     * client and account, so a second active one cannot be inserted.
     */
    protected static function booted(): void
    {
        static::creating(function (self $connection) {
            if ($connection->revoked_at === null) {
                $connection->active_key = self::activeKey($connection->oauth_client_id, $connection->user_id);
            }
        });
    }

    public static function activeKey(string $clientId, int|string $userId): string
    {
        return $clientId.'|'.$userId;
    }

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
     * Passport's OAuth client of the connection: its `name` is what Claude
     * registered («Claude») and `created_at` when.
     *
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Passport::clientModel(), 'oauth_client_id');
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
     * The connections the account takes part in: as the approving account
     * or as one of its accounts (`mcp_connection_accounts`). By account
     * only, never by person.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithAccount(Builder $query, User $account): void
    {
        $query->where(fn (Builder $query) => $query
            ->where('user_id', $account->getKey())
            ->orWhereHas('accounts', fn (Builder $query) => $query->where('user_id', $account->getKey())));
    }

    /**
     * Ends the connection: it stops answering at once, and every
     * authorization code, access token and refresh token of its client
     * for its account is revoked.
     */
    public function revoke(): void
    {
        DB::transaction(function () {
            self::revokeGrants($this->oauth_client_id, $this->user_id);

            $this->forceFill(['revoked_at' => now(), 'active_key' => null])->save();
        });
    }

    /**
     * Revokes every code and token Passport issued to the client for the
     * account: when a connection ends, and before a new one starts, so
     * nothing issued for an earlier connection works on a later one.
     */
    public static function revokeGrants(string $clientId, int|string $userId): void
    {
        $tokens = Passport::token()->newQuery()
            ->where('client_id', $clientId)
            ->where('user_id', $userId);

        Passport::refreshToken()->newQuery()
            ->whereIn('access_token_id', (clone $tokens)->select('id'))
            ->update(['revoked' => true]);
        $tokens->update(['revoked' => true]);

        Passport::authCode()->newQuery()
            ->where('client_id', $clientId)
            ->where('user_id', $userId)
            ->update(['revoked' => true]);
    }
}
