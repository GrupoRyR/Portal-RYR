<?php

namespace App\Http\Controllers\Agent;

use App\Agent\PanelToken;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /agente/token: a fresh panel token for the account in session, asked
 * for by the agent's launcher when the person clicks the button (contract
 * §3.6). The route sits behind `auth`, CSRF and its own rate limit; the
 * token comes from {@see PanelToken::for()}, so the signer and the rules of
 * who may have one are the same as ever.
 *
 * Never a redirect, and never cached. When there is no token to give, for
 * any reason (an account the agent does not accept, or a connection that is
 * not configured), the answer is the same 404 with a fixed body: nothing
 * tells which. Nothing here logs, stores or echoes the token.
 */
class TokenController extends Controller
{
    /** Its own bucket: `throttle:N,1` routes would share one counter per account. */
    public const PER_MINUTE = 10;

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user instanceof User ? PanelToken::for($user) : null;

        if ($token === null) {
            return $this->json(['message' => 'Not found.'], 404);
        }

        return $this->json(['token' => $token], 200);
    }

    /**
     * @param  array<string, string>  $body
     */
    private function json(array $body, int $status): JsonResponse
    {
        return response()->json($body, $status, ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }
}
