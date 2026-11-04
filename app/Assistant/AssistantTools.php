<?php

namespace App\Assistant;

use App\Assistant\Tools\AssistantTool;
use App\Assistant\Tools\BorradorDeAnuncio;
use App\Assistant\Tools\ConnectorTool;
use App\Assistant\Tools\ToolOutcome;
use App\Mcp\Tools\Anuncio;
use App\Mcp\Tools\AnunciosPendientes;
use App\Mcp\Tools\AnunciosRecientes;
use App\Mcp\Tools\BuscarPersonas;
use App\Mcp\Tools\CumpleanosYAniversarios;
use App\Mcp\Tools\EstadoDeTi;
use App\Mcp\Tools\MisApps;
use App\Mcp\Tools\MisAprobaciones;
use App\Mcp\Tools\MisTareas;
use App\Mcp\Tools\PortalTool;
use App\Mcp\Tools\ProximasFechas;
use App\Models\User;
use Throwable;

/**
 * The tools the assistant offers one account, in a fixed order (so the
 * prompt prefix stays the same between questions):
 *
 * - the read tools of the Claude connector, for the signed-in account alone
 *   (App\Assistant\Tools\ConnectorTool over App\Mcp\Tools\*), plus
 *   `anuncios_recientes` and `estado_de_ti`;
 * - `borrador_de_anuncio`, only for accounts that may create announcements.
 *
 * A tool the account does not get, or one that does not exist, is refused
 * as an error for the model, never run. A tool that throws is an error for
 * the model too, and logged without its input.
 */
class AssistantTools
{
    /** @var list<class-string<PortalTool>> */
    public const CONNECTOR_TOOLS = [
        AnunciosRecientes::class,
        AnunciosPendientes::class,
        Anuncio::class,
        ProximasFechas::class,
        CumpleanosYAniversarios::class,
        BuscarPersonas::class,
        MisApps::class,
        MisTareas::class,
        MisAprobaciones::class,
        EstadoDeTi::class,
    ];

    public const UNKNOWN = 'Esa herramienta no existe o no está disponible para esta cuenta.';

    public const FAILED = 'La herramienta falló. Dile a la persona que lo intente de nuevo más tarde.';

    /**
     * @return list<AssistantTool>
     */
    public function for(User $user): array
    {
        $tools = array_map(fn (string $class) => new ConnectorTool($class), self::CONNECTOR_TOOLS);
        $tools[] = new BorradorDeAnuncio;

        return array_values(array_filter($tools, fn (AssistantTool $tool) => $tool->availableTo($user)));
    }

    /**
     * Definitions for the model: name, description, input schema and, for
     * strict tools, `strict`. Deterministic (same order, same bytes) for the
     * same account and role, so the cached prefix holds.
     *
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>, strict?: bool}>
     */
    public function definitions(User $user): array
    {
        return array_map(fn (AssistantTool $tool) => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'inputSchema' => $tool->inputSchema(),
            ...($tool->strict() ? ['strict' => true] : []),
        ], $this->for($user));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function run(User $user, string $name, array $input): ToolOutcome
    {
        foreach ($this->for($user) as $tool) {
            if ($tool->name() !== $name) {
                continue;
            }

            try {
                return $tool->run($user, $input);
            } catch (Throwable $e) {
                logger()->warning('An assistant tool failed.', ['tool' => $name, 'user_id' => $user->id, 'exception' => $e::class]);

                return ToolOutcome::error(self::FAILED);
            }
        }

        return ToolOutcome::error(self::UNKNOWN);
    }
}
