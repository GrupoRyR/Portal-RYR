<?php

namespace App\Announcements;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Why «Enviar recordatorio» recorded nothing ({@see AnnouncementReminders::request()}).
 * The message is for the editor, in Spanish.
 */
class ReminderNotAllowed extends RuntimeException
{
    public static function notShown(): self
    {
        return new self('Solo se pide un recordatorio de un anuncio publicado con lectura obligatoria.');
    }

    public static function nobodyMissing(): self
    {
        return new self('Ya lo leyeron todos.');
    }

    public static function tooSoon(CarbonInterface $nextAllowedAt): self
    {
        return new self('Ya se pidió un recordatorio de este anuncio. Podrás pedir otro el '.AnnouncementReminders::when($nextAllowedAt).'.');
    }
}
