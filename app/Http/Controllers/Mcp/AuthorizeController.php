<?php

namespace App\Http\Controllers\Mcp;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Scope;

/**
 * GET /oauth/authorize: Passport's consent screen, shown every time.
 * Passport would skip it (and approve at once) when the account already
 * has a live token for the client; then no connection would be recorded
 * (App\Mcp\Connections), so every code goes through the approve step.
 */
class AuthorizeController extends AuthorizationController
{
    /**
     * @param  Scope[]  $scopes
     */
    protected function hasGrantedScopes(Authenticatable $user, Client $client, array $scopes): bool
    {
        return false;
    }
}
