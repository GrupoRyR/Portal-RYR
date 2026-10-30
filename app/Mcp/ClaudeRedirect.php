<?php

namespace App\Mcp;

/**
 * The one OAuth redirect the portal accepts: claude.ai's connector
 * callback, compared as an exact string. laravel/mcp's
 * `mcp.redirect_domains` only matches a prefix (any path on the domain),
 * so client registration (App\Http\Controllers\Mcp\RegisterClientController)
 * checks this first. Claude Code and Desktop (localhost) are out of the
 * first version.
 */
final class ClaudeRedirect
{
    public const CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

    public static function allows(mixed $uri): bool
    {
        return $uri === self::CALLBACK;
    }
}
