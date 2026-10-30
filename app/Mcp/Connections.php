<?php

namespace App\Mcp;

use App\Audit\SecurityLog;
use App\Models\McpConnection;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

    /** An account of the connection ended it from /cuenta (log `security`). */
    public const DISCONNECTED = 'mcp.disconnected';

    /** A super admin ended it from Configuración (log `security`). */
    public const REVOKED_BY_ADMIN = 'mcp.revoked_by_admin';

    /** One line per tool call: the tool and the connection, never the content (log `security`). */
    public const TOOL_CALLED = 'mcp.tool_called';

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
     * The active connections the account takes part in (as the approving
     * account or an included one), newest first, with what /cuenta shows.
     *
     * @return Collection<int, McpConnection>
     */
    public function of(User $account): Collection
    {
        return McpConnection::query()
            ->active()
            ->withAccount($account)
            ->with(['client', 'user.company', 'accounts.user.company'])
            ->latest('id')
            ->get();
    }

    /**
     * The active connection with that id if the account takes part in it;
     * null otherwise, whoever else's it is.
     */
    public function findFor(User $account, int $id): ?McpConnection
    {
        return McpConnection::query()->active()->withAccount($account)->whereKey($id)->first();
    }

    /**
     * «Desconectar» on /cuenta: ends the whole connection (any account of
     * it may, it is their data too) and audits `mcp.disconnected`.
     */
    public function disconnect(McpConnection $connection, User $by): bool
    {
        return $this->end($connection, $by, self::DISCONNECTED);
    }

    /**
     * «Revocar» in Configuración: ends the whole connection and audits
     * `mcp.revoked_by_admin`.
     */
    public function revokeByAdmin(McpConnection $connection, User $admin): bool
    {
        return $this->end($connection, $admin, self::REVOKED_BY_ADMIN);
    }

    /**
     * Revokes the connection, its codes and tokens (McpConnection::revoke())
     * and writes one audit line, unless it already ended meanwhile (then
     * nothing is written and the answer is false). The approving account
     * is the subject; the accounts are listed approving one first.
     */
    private function end(McpConnection $connection, User $by, string $event): bool
    {
        return DB::transaction(function () use ($connection, $by, $event) {
            $locked = McpConnection::query()->active()->whereKey($connection->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return false;
            }

            $locked->load(['client', 'user.company', 'accounts.user.company']);
            $locked->revoke();

            $logger = activity(SecurityLog::LOG)
                ->event($event)
                ->causedBy($by)
                ->withProperties([
                    'client' => $locked->client?->name,
                    'connection_id' => $locked->getKey(),
                    'accounts' => self::accountsOf($locked)->map(fn (User $account) => [
                        'user_id' => $account->getKey(),
                        'company' => $account->company?->name,
                        'email' => $account->email,
                    ])->all(),
                ]);

            $locked->user === null ? $logger->performedOn($by) : $logger->performedOn($locked->user);
            $logger->log($event);

            return true;
        });
    }

    /**
     * The accounts of a connection (its relations loaded), the approving
     * one first, then by id. Rows of a deleted account are left out.
     *
     * @return Collection<int, User>
     */
    public static function accountsOf(McpConnection $connection): Collection
    {
        return $connection->accounts
            ->map(fn ($row) => $row->user)
            ->filter()
            ->concat($connection->user === null ? [] : [$connection->user])
            ->unique(fn (User $account) => $account->getKey())
            ->sortBy(fn (User $account) => [$account->getKey() === $connection->user_id ? 0 : 1, $account->getKey()])
            ->values();
    }

    /**
     * The audit line of one tool call (App\Mcp\AuditedCallTool): the
     * tool name and the connection, by the approving account, at the time
     * of the entry, and whether it ended in an error. A `refused` call
     * named no tool or one that does not exist (`tool` is then the name
     * asked for, already cut, or null). Never the arguments nor the
     * result. One insert: the connection and its account are already
     * loaded by `mcp.connection`.
     */
    public function toolCalled(McpConnection $connection, ?string $tool, bool $error = false, bool $refused = false): void
    {
        $properties = ['tool' => $tool, 'connection_id' => $connection->getKey(), 'error' => $error];

        if ($refused) {
            $properties['refused'] = true;
        }

        $logger = activity(SecurityLog::LOG)
            ->event(self::TOOL_CALLED)
            ->withProperties($properties);

        $user = $connection->user;
        $user === null ? $logger->causedByAnonymous() : $logger->causedBy($user)->performedOn($user);

        $logger->log(self::TOOL_CALLED);
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
