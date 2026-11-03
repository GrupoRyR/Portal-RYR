<?php

namespace App\Mcp;

use App\Audit\SecurityLog;
use App\Models\McpConnection;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Records Claude connections. Called by the consent screen's approve step
 * (App\Http\Controllers\Mcp\ApproveController) inside the transaction that
 * issues the authorization code, just before the code, so a code never
 * exists without its connection. The approval also appears in Auditoría
 * as «Seguridad».
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
     *
     * A new connection first revokes whatever the client still holds for
     * the account from an earlier one (codes and tokens), so those never
     * reach it. Two approvals at once meet at the unique `active_key`: the
     * second one reuses the first one's connection.
     */
    public function approved(string $clientId, string $clientName, User $user): McpConnection
    {
        $connection = $this->activeConnection($clientId, $user) ?? $this->newConnection($clientId, $user);

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

    private function activeConnection(string $clientId, User $user): ?McpConnection
    {
        return McpConnection::query()
            ->active()
            ->where('oauth_client_id', $clientId)
            ->where('user_id', $user->getKey())
            ->lockForUpdate()
            ->first();
    }

    private function newConnection(string $clientId, User $user): McpConnection
    {
        try {
            // A savepoint: a duplicate key leaves the outer transaction usable.
            return DB::transaction(function () use ($clientId, $user) {
                $connection = McpConnection::query()->create(['oauth_client_id' => $clientId, 'user_id' => $user->getKey()]);
                McpConnection::revokeGrants($clientId, $user->getKey());

                return $connection;
            });
        } catch (UniqueConstraintViolationException $duplicate) {
            return $this->activeConnection($clientId, $user) ?? throw $duplicate;
        }
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
