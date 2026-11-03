<?php

namespace App\Mcp\Tools;

use App\Models\WorkItem;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * `mis_tareas`: the pending tasks of the accounts of the connection, as
 * their homes list them (App\Mcp\Tools\WorkItemsTool).
 */
#[Name('mis_tareas')]
#[Description('Las tareas pendientes de las cuentas de esta conexión, como las publica la app de tareas y aprobaciones en el inicio del Portal RYR: título, empresa, fecha límite (si tiene), si está vencida y enlace para abrirla en la app de tareas. Primero las que vencen antes (las vencidas al principio) y al final las que no tienen fecha, máximo 20. Solo lectura: para completarla, la persona la abre en la app o en el portal.')]
#[IsReadOnly]
class MisTareas extends WorkItemsTool
{
    protected function kind(): string
    {
        return WorkItem::KIND_TASK;
    }

    protected function key(): string
    {
        return 'tareas';
    }
}
