<?php

namespace App\Work;

/**
 * What «Completar» tells the person (docs/contrato-tareas-aprobaciones.md
 * §6.4). Always the portal's own words, never the app's.
 */
enum CompletionOutcome: string
{
    /** `2xx`, or `409` with `already_done`: done in the portal too. */
    case Completed = 'completed';

    /** `403`: still pending. */
    case Forbidden = 'forbidden';

    /** `404`: removed from the portal. */
    case NotFound = 'not_found';

    /** Any other answer, or none within the timeout: still pending. */
    case Failed = 'failed';

    /** Another press on the same item is still waiting for the app. */
    case Busy = 'busy';

    public function message(): string
    {
        return match ($this) {
            self::Completed => 'Tarea completada.',
            self::Forbidden => 'No puedes completar esta tarea desde el portal. Ábrela en la app.',
            self::NotFound => 'Esta tarea ya no existe en la app.',
            self::Failed => 'No se pudo completar. Inténtalo de nuevo o ábrela en la app.',
            self::Busy => 'Ya estamos completando esta tarea. Espera un momento.',
        };
    }
}
