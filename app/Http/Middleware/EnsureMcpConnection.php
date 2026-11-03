<?php

namespace App\Http\Middleware;

use App\Mcp\ConnectionAccounts;
use App\Mcp\Connections;
use App\Models\McpConnection;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `mcp.connection`, after `auth:api` on /mcp/portal: the bearer token must
 * belong to an active connection (401 otherwise, as for a bad token, so
 * Claude asks to connect again) whose approving account can still connect
 * (403). The usable accounts are left on the request for the tools.
 */
class EnsureMcpConnection
{
    /** Request attribute with the {@see McpConnection} of the call. */
    public const CONNECTION = 'mcp.connection';

    /** Request attribute with the accounts the call may read for. */
    public const ACCOUNTS = 'mcp.accounts';

    public function __construct(
        private readonly Connections $connections,
        private readonly ConnectionAccounts $accounts,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $connection = $this->connections->forToken($request)
            ?? throw new AuthenticationException('Unauthenticated.', ['api']);

        abort_unless($this->accounts->approverMayConnect($connection), 403, 'This account can no longer use the connector.');

        $request->attributes->set(self::CONNECTION, $connection);
        $request->attributes->set(self::ACCOUNTS, $this->accounts->for($connection));
        $this->connections->touch($connection);

        return $next($request);
    }
}
