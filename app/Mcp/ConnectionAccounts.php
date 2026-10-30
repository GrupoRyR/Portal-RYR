<?php

namespace App\Mcp;

use App\Auth\LinkedAccounts;
use App\Models\McpConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which accounts an MCP call may read for: the accounts confirmed for the
 * connection that can still connect ({@see mayConnect()}), and, for any
 * account other than the approving one, are still linked to it. This only
 * removes accounts: an account of the same person that was never
 * confirmed for the connection is never added.
 */
class ConnectionAccounts
{
    public function __construct(private readonly LinkedAccounts $links) {}

    /**
     * A Microsoft home account (not a guest, not an emergency account) of
     * an active company: who may approve a connection and be read for.
     */
    public function mayConnect(User $user): bool
    {
        return $user->exists
            && ! $user->is_emergency
            && $user->tenant_id !== null
            && $user->azure_oid !== null
            // Null means not known yet: refused like a guest.
            && $user->is_guest === false
            && $user->company?->is_active === true;
    }

    public function approverMayConnect(McpConnection $connection): bool
    {
        return $connection->user !== null && $this->mayConnect($connection->user);
    }

    /**
     * The accounts the call may use, oldest first, with their company.
     * Empty when the approving account can no longer connect.
     *
     * @return Collection<int, User>
     */
    public function for(McpConnection $connection): Collection
    {
        if (! $this->approverMayConnect($connection)) {
            return new Collection;
        }

        $approver = $connection->user;

        return User::query()
            ->whereIn('id', $connection->accounts()->select('user_id'))
            ->with('company')
            ->orderBy('id')
            ->get()
            ->filter(fn (User $account) => $this->mayConnect($account)
                && ($account->is($approver) || $this->links->stillLinked($approver, $account)))
            ->values();
    }
}
