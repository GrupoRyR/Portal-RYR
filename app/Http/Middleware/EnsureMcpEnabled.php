<?php

namespace App\Http\Middleware;

use App\Mcp\McpSwitch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `mcp.enabled`: every route of the Claude connector (routes/ai.php)
 * answers 404 while it is off, before anything else runs.
 */
class EnsureMcpEnabled
{
    public function __construct(private readonly McpSwitch $switch) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->switch->enabled(), 404);

        return $next($request);
    }
}
