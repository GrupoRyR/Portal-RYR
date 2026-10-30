<?php

namespace App\Http\Controllers\Mcp;

use App\Mcp\ClaudeRedirect;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

/**
 * POST /oauth/register: dynamic client registration (RFC 7591), public by
 * design. laravel/mcp's own checks run as usual, after a stricter one:
 * every redirect URI must be claude.ai's callback exactly
 * ({@see ClaudeRedirect}), or nothing is registered.
 */
class RegisterClientController extends OAuthRegisterController
{
    public function __invoke(Request $request): JsonResponse
    {
        $uris = $request->input('redirect_uris');

        // A missing or empty list is refused by the package's validation.
        if (is_array($uris) && $uris !== [] && ! collect($uris)->every(fn (mixed $uri) => ClaudeRedirect::allows($uri))) {
            return response()->json([
                'error' => 'invalid_redirect_uri',
                'error_description' => 'Only '.ClaudeRedirect::CALLBACK.' is a permitted redirect URI.',
            ], 400);
        }

        return parent::__invoke($request);
    }
}
