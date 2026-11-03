<?php

namespace App\Announcements;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Why «Reintentar» did not set a list email back to pending, in the words
 * shown to the editor (App\Announcements\AnnouncementListEmails::retry()).
 */
final class ListEmailRetryRefused extends RuntimeException
{
    /**
     * Not a failed email of this announcement to a company it addresses
     * (already sent, retried by someone else, or not there at all).
     */
    public static function notRetryable(): self
    {
        return new self('Este correo ya no se puede reintentar. Recarga la página para ver su estado.');
    }

    public static function tooSoon(CarbonInterface $next): self
    {
        return new self('Ya se reintentó hace poco. Podrás reintentar a las '.AnnouncementListEmails::time($next).'.');
    }
}
