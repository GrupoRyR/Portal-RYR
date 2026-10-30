<?php

namespace App\Mcp;

use App\Audit\SecurityLog;
use App\Models\McpConnection;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Records Claude connections. Called by the consent screen's approve step
 * (App\Http\Controllers\Mcp\ApproveController) inside the transaction that
 * issues the authorization code, so a code never exists without its
 * connection. The approval also appears in Auditoría as «Seguridad».
 */
class Connections
{
    /** Activity log event of an approved connection (log `security`). */
    public const CONNECTED = 'mcp.connected';

    /**
     * Creates the active connection of this client and account, or reuses
     * it when the account approves again. Its accounts become the ones of
     * this approval: today the approving account alone (confirmed by the
     * sign-in that showed the consent screen); C2b adds the linked
     * accounts confirmed with Microsoft.
     */
    public function approved(string $clientId, string $clientName, User $user): McpConnection
    {
        $connection = McpConnection::query()
            ->active()
            ->where('oauth_client_id', $clientId)
            ->where('user_id', $user->getKey())
            ->lockForUpdate()
            ->first()
            ?? McpConnection::query()->create(['oauth_client_id' => $clientId, 'user_id' => $user->getKey()]);

        $connection->accounts()->where('user_id', '!=', $user->getKey())->delete();
        $connection->accounts()->updateOrCreate(['user_id' => $user->getKey()], ['confirmed_at' => now()]);

        activity(SecurityLog::LOG)
            ->event(self::CONNECTED)
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties([
                'client' => $clientName,
                'company' => $user->company?->name,
                'email' => $user->email,
                'connection_id' => $connection->getKey(),
            ])
            ->log(self::CONNECTED);

        return $connection;
    }

    /**
     * The active connection behind the bearer token of an MCP call, or
     * null when there is none (revoked, deleted or never approved).
     */
    public function forToken(Request $request): ?McpConnection
    {
        $user = $request->user();
        $clientId = $user instanceof User ? $user->token()?->oauth_client_id : null;

        if (! is_string($clientId) || $clientId === '') {
            return null;
        }

        return McpConnection::query()
            ->active()
            ->where('oauth_client_id', $clientId)
            ->where('user_id', $user->getKey())
            ->with('user.company')
            ->first();
    }

    /**
     * Writes `last_used_at` at most once a minute per connection.
     */
    public function touch(McpConnection $connection): void
    {
        if ($connection->last_used_at !== null && $connection->last_used_at->gt(now()->subMinute())) {
            return;
        }

        $connection->forceFill(['last_used_at' => now()])->save();
    }
}
