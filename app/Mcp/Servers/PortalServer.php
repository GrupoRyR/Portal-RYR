<?php

namespace App\Mcp\Servers;

use App\Mcp\AuditedCallTool;
use App\Mcp\Tools\Anuncio;
use App\Mcp\Tools\AnunciosPendientes;
use App\Mcp\Tools\BuscarPersonas;
use App\Mcp\Tools\CumpleanosYAniversarios;
use App\Mcp\Tools\MisApps;
use App\Mcp\Tools\ProximasFechas;
use App\Mcp\Tools\QuienSoy;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * The portal's MCP server for Claude (/mcp/portal, routes/ai.php). Read
 * only: every tool reads once per account of the connection, with the
 * portal's own visibility rules (App\Mcp\Tools\PortalTool). The tasks and
 * approvals tools come with C3b. Each tool call is audited (C4).
 */
#[Name('Portal RYR')]
#[Version('1.0.0')]
#[Instructions(<<<'MARKDOWN'
    Conector del Portal RYR (Grupo RYR: Segurex y Derivados). Responde con los datos que la persona ya ve en el portal, solo lectura.
    Cada herramienta lee con todas las cuentas de esta conexión y dice de qué empresa es cada dato («empresas» o «empresa»).
    - `quien_soy`: con qué cuentas del portal está conectada esta conexión (la que la aprobó primero).
    - `anuncios_pendientes`: anuncios que piden confirmar la lectura y aún no se han confirmado; `anuncio` con su id da el texto completo.
    - `proximas_fechas`: festivos de Colombia, eventos y fechas anuales de las empresas.
    - `cumpleanos_y_aniversarios`: cumpleaños (solo de quienes eligieron mostrarlo) y aniversarios de trabajo.
    - `buscar_personas`: el directorio de personas de las empresas.
    - `mis_apps`: las aplicaciones que la persona puede abrir.
    Las fechas vienen en ISO 8601 con la hora de Bogotá y un texto en español. No inventes datos que las herramientas no devuelven, y di siempre de qué empresa es cada dato. Para confirmar una lectura o abrir algo, da el enlace del portal.
    MARKDOWN)]
class PortalServer extends Server
{
    protected array $tools = [
        QuienSoy::class,
        AnunciosPendientes::class,
        Anuncio::class,
        ProximasFechas::class,
        CumpleanosYAniversarios::class,
        BuscarPersonas::class,
        MisApps::class,
    ];

    /**
     * Every tool call leaves one audit line (App\Mcp\AuditedCallTool).
     */
    protected function boot(): void
    {
        $this->addMethod('tools/call', AuditedCallTool::class);
    }
}
