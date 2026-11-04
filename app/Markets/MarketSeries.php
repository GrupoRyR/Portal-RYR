<?php

namespace App\Markets;

/**
 * The catalogue of market series (odd/tasks/mercados-o5.md M1): every code
 * `market_rates` may hold and the band may show, in the order the band
 * shows them. The single source of the codes: App\Markets\MarketBoard
 * iterates it, App\Markets\MarketSync reads the sources it names and
 * App\View\Home\MarketFigure prints each value in its unit.
 *
 * - `scope` «all»: every company sees it; «input»: a company sees it only
 *   when it chose it (`companies.market_codes`, App\Models\Company).
 * - `source` is what `market_rates.source` stores (at most 40 characters).
 * - `sourceKey` is the id of the series at its source: the SDMX dataflow
 *   of the Banco de la República or the FRED series id.
 * - A monthly series is stored on the first day of its month.
 * - `movesByDecision`: the value only changes when someone decides it (the
 *   policy rate). Its change is against the last DIFFERENT value, so the
 *   band shows the last decision instead of «no change since yesterday».
 */
final readonly class MarketSeries
{
    /** The markets' day: what «today» means for the band and the sync. */
    public const TZ = 'America/Bogota';

    public const TRM = 'trm';

    public const EUR = 'eur';

    public const BRENT = 'brent';

    public const TPM = 'tpm';

    public const COLCAP = 'colcap';

    public const ZINC = 'zinc';

    public const COPPER = 'copper';

    public const ALUMINUM = 'aluminum';

    public const BRASS = 'brass';

    public const STEEL = 'steel';

    public const ALKALI = 'alkali';

    public const RESINS = 'resins';

    public const BOTTLES = 'bottles';

    public const DAILY = 'daily';

    public const MONTHLY = 'monthly';

    public const SCOPE_ALL = 'all';

    public const SCOPE_INPUT = 'input';

    public const SOURCE_TRM = 'datos.gov.co';

    public const SOURCE_ECB = 'ecb.europa.eu';

    public const SOURCE_BANREP = 'banrep.gov.co';

    public const SOURCE_FRED = 'fred.stlouisfed.org';

    public function __construct(
        public string $code,
        public string $label,
        public MarketUnit $unit,
        public string $source,
        public string $frequency,
        public string $scope,
        public ?string $sourceKey = null,
        public bool $movesByDecision = false,
    ) {}

    /**
     * Every series by code, in band order: the ones for everyone first,
     * then the inputs.
     *
     * @return array<string, self>
     */
    public static function all(): array
    {
        static $all = null;

        if ($all === null) {
            $all = [];

            foreach (self::catalogue() as $series) {
                $all[$series->code] = $series;
            }
        }

        return $all;
    }

    /**
     * @return list<self>
     */
    private static function catalogue(): array
    {
        return [
            new self(self::TRM, 'Dólar (TRM)', MarketUnit::Pesos, self::SOURCE_TRM, self::DAILY, self::SCOPE_ALL),
            new self(self::EUR, 'Euro', MarketUnit::Pesos, self::SOURCE_ECB, self::DAILY, self::SCOPE_ALL),
            // Daily, published about a week late.
            new self(self::BRENT, 'Petróleo Brent', MarketUnit::UsdPerBarrel, self::SOURCE_FRED, self::DAILY, self::SCOPE_ALL, 'DCOILBRENTEU'),
            new self(self::TPM, 'Tasa de política (BanRep)', MarketUnit::Percent, self::SOURCE_BANREP, self::DAILY, self::SCOPE_ALL, 'DF_CBR_DAILY_HIST', movesByDecision: true),
            new self(self::COLCAP, 'COLCAP', MarketUnit::Points, self::SOURCE_BANREP, self::MONTHLY, self::SCOPE_ALL, 'DF_COLCAP_MONTHLY_HIST'),
            // IMF primary commodity prices, monthly, about two months late.
            new self(self::ZINC, 'Zinc (US$/t)', MarketUnit::UsdPerTonne, self::SOURCE_FRED, self::MONTHLY, self::SCOPE_INPUT, 'PZINCUSDM'),
            new self(self::COPPER, 'Cobre (US$/t)', MarketUnit::UsdPerTonne, self::SOURCE_FRED, self::MONTHLY, self::SCOPE_INPUT, 'PCOPPUSDM'),
            new self(self::ALUMINUM, 'Aluminio (US$/t)', MarketUnit::UsdPerTonne, self::SOURCE_FRED, self::MONTHLY, self::SCOPE_INPUT, 'PALUMUSDM'),
            // US producer price indexes (BLS): prices relative to a base period, not prices.
            new self(self::BRASS, 'Latón (índice)', MarketUnit::Index, self::SOURCE_FRED, self::MONTHLY, self::SCOPE_INPUT, 'WPU102502'),
            new self(self::STEEL, 'Acero (índice)', MarketUnit::Index, self::SOURCE_FRED, self::MONTHLY, self::SCOPE_INPUT, 'WPU1017'),
            new self(self::ALKALI, 'Cloro y soda (índice)', MarketUnit::Index, self::SOURCE_FRED, self::MONTHLY, self::SCOPE_INPUT, 'WPU06130302'),
            new self(self::RESINS, 'Resinas plásticas (índice)', MarketUnit::Index, self::SOURCE_FRED, self::MONTHLY, self::SCOPE_INPUT, 'WPU0662'),
            new self(self::BOTTLES, 'Envases plásticos (índice)', MarketUnit::Index, self::SOURCE_FRED, self::MONTHLY, self::SCOPE_INPUT, 'PCU326160326160'),
        ];
    }

    public static function find(string $code): ?self
    {
        return self::all()[$code] ?? null;
    }

    /**
     * @return list<string> Every code, in band order.
     */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    /**
     * @return list<string> The codes a company may choose, in band order.
     */
    public static function inputCodes(): array
    {
        return array_keys(array_filter(self::all(), fn (self $series) => $series->scope === self::SCOPE_INPUT));
    }

    /**
     * Only the known input codes of a selection, once each, in band order.
     * Anything else (a code for everyone, an unknown code, a non-string,
     * a value that is not a list) is dropped: the selection is data and is
     * never trusted.
     *
     * @return list<string>
     */
    public static function onlyInputs(mixed $codes): array
    {
        if (! is_array($codes)) {
            return [];
        }

        $chosen = array_filter($codes, 'is_string');

        return array_values(array_filter(self::inputCodes(), fn (string $code) => in_array($code, $chosen, true)));
    }

    /**
     * The codes of the band for a selection of inputs: every series for
     * everyone, then the chosen inputs, in band order.
     *
     * @return list<string>
     */
    public static function codesFor(mixed $inputs): array
    {
        $chosen = self::onlyInputs($inputs);

        return array_values(array_filter(
            self::codes(),
            fn (string $code) => self::all()[$code]->scope === self::SCOPE_ALL || in_array($code, $chosen, true),
        ));
    }

    public function isMonthly(): bool
    {
        return $this->frequency === self::MONTHLY;
    }
}
