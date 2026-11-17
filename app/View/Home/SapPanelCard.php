<?php

namespace App\View\Home;

use App\Management\SapFigures;
use App\Management\SapFigureSet;
use App\Management\SapSeller;
use Carbon\CarbonImmutable;

/**
 * The «Panel gerencial» card of the home on «Bases de datos SAP»
 * (odd/tasks/panel-gerencial-sap.md), ready to print: the month's net sale,
 * gross profit and margin with its coverage notice, the receivables and
 * their ranges, and the top sellers. Pesos are whole, with the portal's
 * separators («$1.250.000.000», «-$50.000»), so they read like SAP's
 * reports. Without figures ($available false) the card only says that SAP
 * could not be read. Only built for a «Gerencia» account of the same
 * company (App\View\Home\HomePageBuilder).
 */
final readonly class SapPanelCard
{
    public const UNAVAILABLE = 'No pudimos leer las cifras de SAP. Inténtalo de nuevo en unos minutos.';

    /**
     * @param  list<array{label: string, value: string, share: int}>  $ranges  «Sin vencer» and the seven ranges, with their share of the total (0–100).
     * @param  list<array{name: string, value: string}>  $sellers
     */
    public function __construct(
        public string $period,
        public bool $available,
        public ?string $netSales = null,
        public ?string $grossProfit = null,
        public ?string $margin = null,
        public ?string $coverageNotice = null,
        public ?string $receivables = null,
        public array $ranges = [],
        public ?string $rangesNotice = null,
        public array $sellers = [],
        public string $source = 'Fuente: Bases de datos SAP',
        public ?string $refreshAction = null,
        public ?string $refreshStatus = null,
        public ?string $updatedAt = null,
    ) {}

    /**
     * @param  string|null  $refreshAction  The form action of «Actualizar ventas y cartera»; null hides the button (API not configured).
     * @param  string|null  $refreshStatus  How the last press goes (App\Management\SapRefresh::status()).
     * @param  CarbonImmutable|null  $updatedAt  The last update of the data, for «Datos actualizados a las …»; null leaves the line out.
     */
    public static function from(?SapFigureSet $figures, CarbonImmutable $now, ?string $refreshAction = null, ?string $refreshStatus = null, ?CarbonImmutable $updatedAt = null): self
    {
        $updated = $updatedAt === null ? null : self::updated($updatedAt, $now);

        $month = ($figures?->month ?? $now->setTimezone(SapFigures::TIMEZONE)->startOfMonth())->locale('es');
        // «Noviembre de 2026».
        $period = $month->isoFormat('MMMM [de] YYYY');
        $period = mb_strtoupper(mb_substr($period, 0, 1)).mb_substr($period, 1);

        if ($figures === null) {
            return new self(
                period: $period,
                available: false,
                refreshAction: $refreshAction,
                refreshStatus: $refreshStatus,
                updatedAt: $updated,
            );
        }

        $margin = $figures->margin();
        $coverage = $figures->coverage();
        $total = $figures->receivables;

        return new self(
            period: $period,
            available: true,
            netSales: self::pesos($figures->netSales),
            grossProfit: self::pesos($figures->grossProfit),
            margin: $margin === null ? null : self::percent($margin * 100, round: true),
            coverageNotice: $coverage === null
                ? 'Sin ventas registradas en el mes: no hay margen que calcular.'
                : 'Margen calculado sobre el '.self::percent($coverage * 100, round: false).' de la venta neta.',
            receivables: self::pesos($total),
            ranges: array_map(fn (string $column, string $label) => [
                'label' => $label,
                'value' => self::pesos($figures->ranges[$column] ?? 0.0),
                'share' => $total > 0 ? (int) max(0, min(100, round(($figures->ranges[$column] ?? 0.0) / $total * 100))) : 0,
            ], array_keys(SapFigureSet::RANGES), SapFigureSet::RANGES),
            rangesNotice: $figures->rangesMatch() ? null : sprintf(
                'Los rangos no suman la cartera total: %s %s.',
                $figures->rangesDifference() > 0 ? 'faltan' : 'sobran',
                self::pesos(abs($figures->rangesDifference())),
            ),
            sellers: array_map(fn (SapSeller $seller) => [
                'name' => $seller->name,
                'value' => self::pesos($seller->netSales),
            ], $figures->topSellers),
            refreshAction: $refreshAction,
            refreshStatus: $refreshStatus,
            updatedAt: $updated,
        );
    }

    /** «Datos actualizados a las 09:15», or «… el 16 de noviembre a las 02:05» for another day (Bogotá). */
    private static function updated(CarbonImmutable $at, CarbonImmutable $now): string
    {
        $at = $at->setTimezone(SapFigures::TIMEZONE)->locale('es');
        $time = 'a las '.$at->format('H:i');

        if ($at->isSameDay($now->setTimezone(SapFigures::TIMEZONE))) {
            return "Datos actualizados {$time}";
        }

        return 'Datos actualizados el '.$at->isoFormat('D [de] MMMM').($at->year === $now->year ? '' : " de {$at->year}")." {$time}";
    }

    /** Whole pesos: «$1.250.000.000», «-$50.000», «$0». */
    public static function pesos(float $value): string
    {
        $amount = round(abs($value));

        return ($value < 0 && $amount > 0 ? '-' : '').'$'.number_format($amount, 0, ',', '.');
    }

    /**
     * «25 %», «25,3 %». A coverage is cut, never rounded up, so 99,96 %
     * never reads as the whole sale.
     */
    private static function percent(float $value, bool $round): string
    {
        $tenths = $round ? round($value * 10) : floor(round($value * 10, 6));
        $text = number_format($tenths / 10, 1, ',', '.');

        return (str_ends_with($text, ',0') ? substr($text, 0, -2) : $text).' %';
    }
}
