<?php

namespace App\Mcp;

/**
 * Whether the Claude connector is on for the whole portal. The only place
 * that reads it (middleware `mcp.enabled`), so the Admin toggle (C4) can
 * change where the answer comes from. Today: PORTAL_MCP_ENABLED.
 */
class McpSwitch
{
    public function enabled(): bool
    {
        return config('portal.mcp.enabled') === true;
    }
}
