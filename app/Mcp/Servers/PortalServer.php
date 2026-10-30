<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\QuienSoy;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * The portal's MCP server for Claude (/mcp/portal, routes/ai.php). Read
 * only. C2a ships one placeholder tool; C3 adds the real ones.
 */
#[Name('Portal RYR')]
#[Version('1.0.0')]
#[Instructions(<<<'MARKDOWN'
    Conector del Portal RYR (Grupo RYR: Segurex y Derivados). Responde con los datos que la persona ya ve en el portal, solo lectura.
    Usa `quien_soy` para saber con qué cuentas del portal está conectada esta conexión (la que la aprobó primero): nombre, correo y empresa de cada una.
    No inventes datos que las herramientas no devuelven, y di siempre de qué empresa es cada dato.
    MARKDOWN)]
class PortalServer extends Server
{
    protected array $tools = [
        QuienSoy::class,
    ];
}
