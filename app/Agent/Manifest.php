<?php

namespace App\Agent;

use App\Agent\Views\AgentViews;

/**
 * The Portal's tool manifest for the agent (connection contract v0.4, §3.5),
 * served at /.well-known/agente-ryr.json. Tool texts are Spanish on purpose:
 * the agent shows and reads them. Everything else is English.
 *
 * Two roles, lowest first: `empleado` and `gerencia`. Both are «per person»
 * roles (`roles_por_persona`): a tool that declares the `usuario` filter
 * (only `mis_pendientes`) always shows the asker's own rows; the others are
 * company-wide and open from their `rol_minimo`. Every tool declares the
 * `empresa` filter: the agent puts the company of the token there.
 *
 * Columns come from {@see AgentViews}, so they cannot differ from the views.
 * The manifest carries no data, only names, columns and descriptions.
 *
 * Bump {@see VERSION} on every change of the manifest: ManifestTest keeps a
 * hash of the content and fails when it changes without a new version.
 */
final class Manifest
{
    public const VERSION = '2026-11-12.1';

    /**
     * @param  string  $appUrl  https origin of this Portal, the one registered in the agent.
     * @return array<string, mixed>
     */
    public static function build(string $appUrl): array
    {
        return [
            'version' => self::VERSION,
            'app' => [
                'slug' => (string) config('agent.slug'),
                'nombre' => (string) config('agent.name'),
                'empresas' => array_values(config('agent.companies')),
                'url' => $appUrl,
            ],
            'roles' => ['empleado', 'gerencia'],
            'roles_por_persona' => ['empleado', 'gerencia'],
            // Logical names: the real database name and the cPanel prefix are not published.
            'datos' => ['base' => 'portal', 'usuario_ro' => 'agente_portal_ro'],
            'herramientas' => self::tools(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function tools(): array
    {
        return [
            self::query(
                'anuncios_vigentes',
                'Consultando los anuncios',
                'Anuncios del Portal publicados ahora para la empresa de la persona, con título, texto, fechas, si están fijados y si piden confirmar la lectura; los fijados primero y luego los más recientes. Usar para «¿hay anuncios?» o «¿qué dice el anuncio sobre...?». No dice si la persona ya los leyó.',
                'v_anuncios_vigentes',
                ['exige_lectura' => ['type' => 'boolean']],
                [['fijado', 'desc'], ['desde', 'desc']],
                20,
            ),
            self::query(
                'proximos_eventos',
                'Consultando los próximos eventos',
                'Eventos de la empresa que aún no terminan, con título, lugar, inicio, fin y la próxima fecha cuando se repiten cada año, del más próximo al más lejano. Usar para preguntas de agenda y fechas importantes. No incluye los festivos de Colombia ni los cumpleaños.',
                'v_eventos_proximos',
                [],
                [['proxima_fecha', 'asc'], ['inicio', 'asc']],
                30,
            ),
            self::query(
                'mis_pendientes',
                'Consultando tus tareas y aprobaciones',
                'Tareas y aprobaciones pendientes de la persona en la empresa de su cuenta: título, fecha límite, si está vencida y enlace a la app de tareas; las vencidas primero. Siempre son las de quien pregunta. No muestra las de otra cuenta de la persona en otra empresa ni permite completarlas.',
                'v_tareas_pendientes',
                ['tipo' => ['type' => 'string', 'enum' => ['tarea', 'aprobacion']]],
                [['vencida', 'desc'], ['vence', 'asc']],
                50,
                ['empresa' => 'empresa', 'usuario' => 'usuario_correo'],
            ),
            self::query(
                'estado_de_ti',
                'Consultando el estado de TI',
                'Estado de los servicios de TI de la empresa (correo, internet, VPN...) y de las aplicaciones: operando, degradado, caído o en mantenimiento, con su mensaje y la hora de la última actualización. Usar cuando alguien pregunta si algo está fallando. No abre tickets ni diagnostica la causa.',
                'v_estado_ti',
                [],
                [['tipo', 'asc'], ['nombre', 'asc']],
                50,
            ),
            self::query(
                'kpis_gerenciales',
                'Consultando las cifras de gerencia',
                'Cifras del panel gerencial de la empresa: nombre, valor, unidad, meta, periodo, fecha de corte y fuente. Usar para preguntas sobre los indicadores que gerencia ve en el Portal. No trae el detalle contable ni compara empresas.',
                'v_kpis_empresa',
                [],
                [['etiqueta', 'asc']],
                30,
                ['empresa' => 'empresa'],
                'gerencia',
            ),
        ];
    }

    /**
     * One read-only tool over a view.
     *
     * @param  array<string, array<string, mixed>>  $parameters  Each name is a view column, compared by equality.
     * @param  list<array{0: string, 1: string}>  $order  Up to three [column, direction].
     * @param  array<string, string>  $filters  Required filters (parameter => column).
     * @return array<string, mixed>
     */
    private static function query(
        string $name,
        string $label,
        string $description,
        string $view,
        array $parameters,
        array $order,
        int $rowLimit,
        array $filters = ['empresa' => 'empresa'],
        string $minimumRole = 'empleado',
    ): array {
        return [
            'nombre' => $name,
            'etiqueta' => $label,
            'tipo' => 'consulta',
            'descripcion' => $description,
            'fuente' => ['vista' => $view, 'columnas' => AgentViews::columns($view)],
            'parametros' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => [],
                // An empty PHP array would encode as a list; the schema needs an object.
                'properties' => $parameters === [] ? new \stdClass : $parameters,
            ],
            'orden' => array_map(fn (array $o) => ['columna' => $o[0], 'direccion' => $o[1]], $order),
            'filtros_obligatorios' => $filters,
            'rol_minimo' => $minimumRole,
            'limite_filas' => $rowLimit,
        ];
    }
}
