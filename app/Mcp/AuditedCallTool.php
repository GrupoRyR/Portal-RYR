<?php

namespace App\Mcp;

use App\Http\Middleware\EnsureMcpConnection;
use App\Models\McpConnection;
use Generator;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * `tools/call` of the portal's MCP server (App\Mcp\Servers\PortalServer):
 * laravel/mcp's own handler, plus one audit line per call
 * (Connections::toolCalled(), `mcp.tool_called`) with the tool name only,
 * never the arguments nor what it returned:
 * - a call that ran is logged after it, flagged `error` when its result
 *   says `isError` (bad input, a refusal of the tool);
 * - a missing or unknown tool name is refused by the parent and logged as
 *   `refused`, with the name cut to {@see NAME_MAX} characters.
 *
 * A streaming tool (one returning a Generator) would be logged before its
 * body runs, without the `error` flag of its last message. No tool of the
 * portal streams (they return plain JSON, so SSE never opens); one that
 * does needs this class to log after the stream ends.
 */
class AuditedCallTool extends CallTool
{
    public const NAME_MAX = 64;

    public function __construct(private readonly Connections $connections) {}

    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        $connection = request()->attributes->get(EnsureMcpConnection::CONNECTION);
        $name = $request->get('name');
        $tool = is_string($name) ? mb_substr($name, 0, self::NAME_MAX) : null;

        try {
            $response = parent::handle($request, $context);
        } catch (JsonRpcException $refused) {
            if ($connection instanceof McpConnection) {
                $this->connections->toolCalled($connection, $tool, error: true, refused: true);
            }

            throw $refused;
        }

        if ($connection instanceof McpConnection) {
            $this->connections->toolCalled($connection, $tool, error: self::isError($response));
        }

        return $response;
    }

    private static function isError(Generator|JsonRpcResponse $response): bool
    {
        $result = $response instanceof JsonRpcResponse ? ($response->content['result'] ?? null) : null;

        return is_array($result) && ($result['isError'] ?? false) === true;
    }
}
