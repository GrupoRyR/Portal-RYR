<?php

namespace App\Mcp;

use App\Audit\SecurityLog;
use App\Models\McpConnection;
use App\Models\User;
use Carbon\CarbonInterface;
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

    public function __construct(private readonly ConnectionAccounts $accounts) {}

    /**
     * Creates the active connection of this client and account, or reuses
     * it when the account approves again. Its accounts become exactly the
     * ones of this approval: the approving account (confirmed by the
     * sign-in that showed the consent screen) and the linked accounts
     * confirmed with Microsoft for this pending authorization
     * (App\Mcp\ConsentAccounts) that may still be used
     * (ConnectionAccounts::usable()). Any other account leaves, also on a
     * new approval of the same connection.
     *
     * A new connection first revokes whatever the client still holds for
     * the account from an earlier one (codes and tokens), so those never
     * reach it. Two approvals at once meet at the unique `active_key`: the
     * second one reuses the first one's connection.
     *
     * @param  array<int, CarbonInterface>  $confirmed  When each linked account was confirmed, by its id.
     */
    public function approved(string $clientId, string $clientName, User $user, array $confirmed = []): McpConnection
    {
        $connection = $this->activeConnection($clientId, $user) ?? $this->newConnection($clientId, $user);

        $included = User::query()
            ->whereKey(array_keys($confirmed))
            ->whereKeyNot($user->getKey())
            ->with('company')
            ->orderBy('id')
            ->get()
            ->filter(fn (User $account) => $this->accounts->usable($user, $account))
            ->values();
        $accounts = [$user->getKey() => now()];

        foreach ($included as $account) {
            $accounts[$account->getKey()] = $confirmed[$account->getKey()];
        }

        $connection->accounts()->whereNotIn('user_id', array_keys($accounts))->delete();

        foreach ($accounts as $id => $confirmedAt) {
            $connection->accounts()->updateOrCreate(['user_id' => $id], ['confirmed_at' => $confirmedAt]);
        }

        activity(SecurityLog::LOG)
            ->event(self::CONNECTED)
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties([
                'client' => $clientName,
                'company' => $user->company?->name,
                'email' => $user->email,
                'connection_id' => $connection->getKey(),
                'accounts' => collect([$user])->concat($included)->map(fn (User $account) => [
                    'user_id' => $account->getKey(),
                    'company' => $account->company?->name,
                    'email' => $account->email,
                ])->all(),
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
