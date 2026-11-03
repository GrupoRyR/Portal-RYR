<?php

namespace App\Mcp\Tools;

use App\Models\WorkItem;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * `mis_aprobaciones`: what the accounts of the connection have to approve,
 * as their homes list it (App\Mcp\Tools\WorkItemsTool). Approving or
 * rejecting happens only in the tasks app (contract §7).
 */
#[Name('mis_aprobaciones')]
#[Description('Lo que las cuentas de esta conexión tienen por aprobar, como lo publica la app de tareas y aprobaciones en el inicio del Portal RYR: título, empresa, fecha límite (si tiene), si está vencida y enlace para abrirla en la app de tareas. Primero lo que vence antes y al final lo que no tiene fecha, máximo 20. Solo lectura: se aprueba o se rechaza únicamente en la app de tareas.')]
#[IsReadOnly]
class MisAprobaciones extends WorkItemsTool
{
    protected function kind(): string
    {
        return WorkItem::KIND_APPROVAL;
    }

    protected function key(): string
    {
        return 'aprobaciones';
    }
}
