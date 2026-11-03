<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Mcp\Connections;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * «Desconectar» of «Conexiones con Claude» on /cuenta (POST): ends the
 * whole connection, with its codes and tokens, and audits
 * `mcp.disconnected` (App\Mcp\Connections::disconnect()). It works while
 * the connector is off too: turning it on again resumes every connection,
 * so people must be able to end theirs first.
 *
 * Any account of the connection may end it, the approving one or an
 * included one: Claude reads their data too. A connection the account
 * does not take part in (or no longer active) is a 404, never a 403, so
 * the answer reveals nothing about others' connections. Being the same
 * person is not taking part.
 *
 * No fresh Microsoft confirmation (microsoft.recent), unlike unlinking:
 * disconnecting only removes access, connecting again needs a Microsoft
 * sign-in and the consent screen, and someone whose Claude account was
 * taken over should be able to cut it off at once.
 */
class ClaudeConnectionController extends Controller
{
    public function destroy(Request $request, int $connection, Connections $connections): RedirectResponse
    {
        $user = $request->user();
        $found = $user instanceof User ? $connections->findFor($user, $connection) : null;

        abort_if($found === null, 404);

        $client = (string) $found->client?->name;

        // findFor() read without a lock: a connection ended meanwhile is
        // answered like one that is not there.
        abort_unless($connections->disconnect($found, $user), 404);

        return redirect()->route('account.show')
            ->with('status', "Desconectaste Claude («{$client}»). Ya no puede leer los datos de esas cuentas.");
    }
}
