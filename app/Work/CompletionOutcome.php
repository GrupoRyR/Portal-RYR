<?php

namespace App\Work;

/**
 * What «Completar» tells the person (docs/contrato-tareas-aprobaciones.md
 * §6.4). Always the portal's own words, never the app's.
 */
enum CompletionOutcome: string
{
    /** `200` with `completed`, or `409` with `already_done`: done in the portal too. */
    case Completed = 'completed';

    /** `403` with `not_assignee` or `not_completable`: still pending. */
    case Forbidden = 'forbidden';

    /** `404` with `not_found`: removed from the portal. */
    case NotFound = 'not_found';

    /** Any other answer, or none within the timeout: still pending. */
    case Failed = 'failed';

    /** Another press on the same item is still waiting for the app. */
    case Busy = 'busy';

    /**
     * The item changed while the press waited: it is no longer one the
     * account may complete, or the app's own PUT with a higher version
     * landed during the call. The portal's copy is left as it is.
     */
    case Stale = 'stale';

    public function message(): string
    {
        return match ($this) {
            self::Completed => 'Tarea completada.',
            self::Forbidden => 'No puedes completar esta tarea desde el portal. Ábrela en la app.',
            self::NotFound => 'Esta tarea ya no existe en la app.',
            self::Failed => 'No se pudo completar. Inténtalo de nuevo o ábrela en la app.',
            self::Busy => 'Ya estamos completando esta tarea. Espera un momento.',
            self::Stale => 'Esta tarea cambió mientras tanto. Revisa tu lista de tareas.',
        };
    }
}
