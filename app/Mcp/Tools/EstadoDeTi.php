<?php

namespace App\Mcp\Tools;

use App\ItStatus\ItStatusBoard;
use App\ItStatus\ItStatusItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * `estado_de_ti`: «Estado de TI» of each account's company, as its home
 * shows it (App\ItStatus\ItStatusBoard::for(): the apps it can open that
 * are in maintenance or have a current failure report, and the company's
 * manual services that are not operational, worst first). An account
 * without an active company (or the emergency account) gets
 * `disponible: false`.
 *
 * Used by the portal assistant (App\Assistant\AssistantTools); not
 * registered in the Claude connector (App\Mcp\Servers\PortalServer) yet.
 */
#[Name('estado_de_ti')]
#[Description('«Estado de TI» del Portal RYR para la empresa de la cuenta: si todo funciona con normalidad, qué aplicaciones están en mantenimiento o con fallas y qué servicios de TI (correo, internet, VPN...) no están operativos, con su mensaje. Incluye el enlace para reportar un problema a soporte TI, si está configurado.')]
#[IsReadOnly]
class EstadoDeTi extends PortalTool
{
    public function __construct(private readonly ItStatusBoard $board) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $accounts = $this->accounts($request);

        if ($accounts === null) {
            return Response::error(self::NO_ACCOUNTS);
        }

        $boards = [];

        foreach ($accounts as $account) {
            $status = $this->board->for($account);

            $boards[] = [
                'empresa' => self::company($account),
                'disponible' => $status->available,
                'resumen' => $status->available ? $status->level->headline() : null,
                'todo_bien' => $status->available && $status->allGood(),
                'apps' => array_map(self::item(...), $status->apps),
                'servicios' => array_map(self::item(...), $status->services),
                'reportar_problema_url' => $status->reportHref,
            ];
        }

        return Response::structured(['estado' => $boards]);
    }

    /**
     * @return array{nombre: string, estado: string, mensaje: ?string, hasta: ?string, hasta_texto: ?string}
     */
    private static function item(ItStatusItem $item): array
    {
        return [
            'nombre' => $item->name,
            'estado' => $item->label,
            'mensaje' => $item->message,
            'hasta' => $item->until === null ? null : self::iso($item->until),
            'hasta_texto' => $item->until === null ? null : self::momentLabel($item->until),
        ];
    }

    /**
     * No input.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
