<?php

namespace App\View\Home;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * «Buenos días» and the date line of the home screen, always in Bogotá time
 * whatever the server time zone.
 */
final readonly class Greeting
{
    public const TIMEZONE = 'America/Bogota';

    public function __construct(
        public string $salutation,
        public string $dateLine,
    ) {}

    public static function at(CarbonInterface $moment): self
    {
        $local = $moment->toImmutable()->setTimezone(self::TIMEZONE)->locale('es');
        $hour = $local->hour;

        $salutation = match (true) {
            $hour >= 5 && $hour < 12 => 'Buenos días',
            $hour >= 12 && $hour < 19 => 'Buenas tardes',
            default => 'Buenas noches',
        };

        // «Lunes, 5 de octubre»
        return new self($salutation, Str::ucfirst($local->isoFormat('dddd, D [de] MMMM')));
    }

    public static function firstName(?string $name): string
    {
        return Str::before(trim((string) $name), ' ');
    }
}
