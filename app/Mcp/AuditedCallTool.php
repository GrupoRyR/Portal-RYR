<?php

namespace App\Mcp;

use App\Http\Middleware\EnsureMcpConnection;
use App\Models\McpConnection;
use Generator;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * `tools/call` of the portal's MCP server (App\Mcp\Servers\PortalServer):
 * laravel/mcp's own handler, plus one audit line per call
 * (Connections::toolCalled(), `mcp.tool_called`). Only a tool that exists
 * is logged (an unknown name is refused by the parent before), and only
 * its name: never the arguments nor what it returned.
 */
class AuditedCallTool extends CallTool
{
    public function __construct(private readonly Connections $connections) {}

    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        $response = parent::handle($request, $context);

        $connection = request()->attributes->get(EnsureMcpConnection::CONNECTION);

        if ($connection instanceof McpConnection) {
            $this->connections->toolCalled($connection, (string) $request->get('name'));
        }

        return $response;
    }
}
