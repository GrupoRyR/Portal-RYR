<?php

namespace App\Http\Controllers\Mcp;

use App\Mcp\Connections;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use League\OAuth2\Server\AuthorizationServer;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /oauth/authorize: «Aprobar» on the consent screen. Passport's own
 * approve step, plus the connection record (App\Mcp\Connections) in the
 * same transaction as the authorization code. The account approving must
 * be the one the screen was shown to (not, say, another company's account
 * switched to in another tab).
 */
class ApproveController extends ApproveAuthorizationController
{
    public function __construct(AuthorizationServer $server, private readonly Connections $connections)
    {
        parent::__construct($server);
    }

    public function approve(Request $request, ResponseInterface $psrResponse): Response
    {
        $authRequest = $this->getAuthRequestFromSession($request);
        $user = $request->user();

        abort_unless(
            $user instanceof User && (string) $authRequest->getUser()?->getIdentifier() === (string) $user->getAuthIdentifier(),
            403,
        );

        $authRequest->setAuthorizationApproved(true);
        $client = $authRequest->getClient();

        // The connection first: starting a new one revokes the client's
        // earlier codes and tokens for the account, never this new code.
        return DB::transaction(function () use ($authRequest, $psrResponse, $client, $user) {
            $this->connections->approved($client->getIdentifier(), $client->getName(), $user);

            return $this->withErrorHandling(fn () => $this->convertResponse(
                $this->server->completeAuthorizationRequest($authRequest, $psrResponse)
            ), $authRequest->getGrantTypeId() === 'implicit');
        });
    }
}
