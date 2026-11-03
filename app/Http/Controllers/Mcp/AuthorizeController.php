<?php

namespace App\Http\Controllers\Mcp;

use App\Mcp\ConsentAccounts;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Scope;
use League\OAuth2\Server\AuthorizationServer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /oauth/authorize: Passport's consent screen, with four changes.
 *
 * - Only S256 PKCE: league/oauth2-server also takes `plain`, and uses it
 *   when `code_challenge_method` is left out.
 * - `prompt=login` is ignored: Passport would sign the user out of the
 *   portal and drop the session, which any registered client could
 *   trigger from another site.
 * - The screen is shown every time: Passport would skip it (and approve
 *   at once) when the account already has a live token for the client;
 *   then no connection would be recorded (App\Mcp\Connections).
 * - The screen also offers the linked accounts the person may include,
 *   and shows the ones already confirmed with Microsoft for this pending
 *   authorization (App\Mcp\ConsentAccounts, C2b).
 */
class AuthorizeController extends AuthorizationController
{
    public function __construct(
        AuthorizationServer $server,
        StatefulGuard $guard,
        ClientRepository $clients,
        private readonly ConsentAccounts $consent,
    ) {
        parent::__construct($server, $guard, $clients);
    }

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

        // Read before Passport replaces it with the new pending authorization.
        $previous = $request->session()->get('authToken');

        $view = new ConsentView($viewResponse, fn (array $parameters) => ($parameters['user'] ?? null) instanceof User
            ? $this->consent->shown($request, $parameters['user'], is_string($previous) ? $previous : null)
            : []);

        return parent::authorize($psrRequest, $request, $psrResponse, $view);
    }

    /**
     * @param  Scope[]  $scopes
     */
    protected function hasGrantedScopes(Authenticatable $user, Client $client, array $scopes): bool
    {
        return false;
    }
}
