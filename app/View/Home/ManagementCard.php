<?php

namespace App\View\Home;

use App\Management\ManagementSnapshot;
use Carbon\CarbonImmutable;

/**
 * The «Panel gerencial» card of the home (App\Management\ManagementPanel),
 * ready to print: «Octubre, al cierre de ayer», the figures in the robot's
 * order (the first one large) and «Fuente: SAP Business One». Only built
 * for a «Gerencia» account of the same company (ManagementPanel::forViewer()).
 */
final readonly class ManagementCard
{
    /**
     * @param  list<KpiFigure>  $figures
     */
    public function __construct(
        public string $closing,
        public string $asOfIso,
        public string $source,
        public array $figures,
    ) {}

    public static function from(ManagementSnapshot $snapshot, CarbonImmutable $today): self
    {
        $asOf = $snapshot->asOf->setTimezone((string) config('app.timezone'));
        $day = $asOf->startOfDay();

        $closing = match (true) {
            $day->equalTo($today) => 'al cierre de hoy',
            $day->equalTo($today->subDay()) => 'al cierre de ayer',
            default => 'al cierre del '.$asOf->locale('es')->isoFormat('D [de] MMMM').($asOf->year === $today->year ? '' : " de {$asOf->year}"),
        };

        return new self(
            closing: "{$snapshot->periodLabel}, {$closing}",
            asOfIso: $asOf->toIso8601String(),
            source: "Fuente: {$snapshot->sourceLabel}",
            figures: array_map(KpiFigure::from(...), $snapshot->items),
        );
    }
}
