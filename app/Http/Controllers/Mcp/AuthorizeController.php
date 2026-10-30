<?php

namespace App\Http\Controllers\Mcp;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Laravel\Passport\Client;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Scope;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /oauth/authorize: Passport's consent screen, with three changes.
 *
 * - Only S256 PKCE: league/oauth2-server also takes `plain`, and uses it
 *   when `code_challenge_method` is left out.
 * - `prompt=login` is ignored: Passport would sign the user out of the
 *   portal and drop the session, which any registered client could
 *   trigger from another site.
 * - The screen is shown every time: Passport would skip it (and approve
 *   at once) when the account already has a live token for the client;
 *   then no connection would be recorded (App\Mcp\Connections).
 */
class AuthorizeController extends AuthorizationController
{
    public function authorize(
        ServerRequestInterface $psrRequest,
        Request $request,
        ResponseInterface $psrResponse,
        AuthorizationViewResponse $viewResponse
    ): Response|AuthorizationViewResponse {
        if ($request->query('code_challenge_method') !== 'S256') {
            return response()->json([
                'error' => 'invalid_request',
                'error_description' => 'code_challenge_method must be S256.',
            ], 400);
        }

        $prompt = $request->string('prompt')->explode(' ')->map(trim(...))->filter()
            ->reject(fn (string $value) => $value === 'login');
        $request->query->set('prompt', $prompt->implode(' '));

        return parent::authorize($psrRequest, $request, $psrResponse, $viewResponse);
    }

    /**
     * @param  Scope[]  $scopes
     */
    protected function hasGrantedScopes(Authenticatable $user, Client $client, array $scopes): bool
    {
        return false;
    }
}
