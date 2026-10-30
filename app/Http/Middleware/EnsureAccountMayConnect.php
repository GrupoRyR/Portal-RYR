<?php

namespace App\Http\Middleware;

use App\Mcp\ConnectionAccounts;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `mcp.account`, on the consent screen and its approve step: a signed-in
 * account that may not connect Claude (guest, emergency, no Microsoft
 * identity, inactive company) gets the refusal page (403) and no code.
 * Guests pass: Passport sends them to the Microsoft sign-in.
 */
class EnsureAccountMayConnect
{
    public function __construct(private readonly ConnectionAccounts $accounts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $this->accounts->mayConnect($user)) {
            return response()->view('mcp.refused', ['user' => $user], 403);
        }

        return $next($request);
    }
}
