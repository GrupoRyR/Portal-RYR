<?php

namespace App\Audit;

use App\Models\Company;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The Auditoría filters, read from GET parameters so a filtered view can be
 * bookmarked: `q` (person name/email or app name/slug), `tipo`, `empresa`
 * (company slug) and `periodo` (`hoy`, `7`, `30`, `90` or `rango` with
 * `desde`/`hasta` as Y-m-d). Days are calendar days in the app timezone
 * (Bogotá). Anything unexpected falls back to the default, never an error.
 */
final readonly class AuditFilters
{
    public const TYPE_ALL = 'todo';

    public const TYPE_CHANGES = 'cambios';

    public const TYPE_LAUNCHES = 'apps';

    public const TYPE_SECURITY = 'seguridad';

    /** Value => label, in the order of the type control. */
    public const TYPES = [
        self::TYPE_ALL => 'Todo',
        self::TYPE_CHANGES => 'Cambios',
        self::TYPE_LAUNCHES => 'Apps abiertas',
        self::TYPE_SECURITY => 'Seguridad',
    ];

    /** Value => label, in the order of the period control. */
    public const PERIODS = [
        'hoy' => 'Hoy',
        '7' => 'Últimos 7 días',
        '30' => 'Últimos 30 días',
        '90' => 'Últimos 90 días',
        'rango' => 'Rango…',
    ];

    public const DEFAULT_PERIOD = '7';

    public const MAX_SEARCH = 100;

    public function __construct(
        public string $search,
        public string $type,
        public ?Company $company,
        public string $period,
        public ?CarbonImmutable $from,
        public ?CarbonImmutable $to,
    ) {}

    /**
     * @param  array<string, mixed>  $input  Usually the request query.
     */
    public static function fromArray(array $input): self
    {
        $string = fn (string $key) => is_string($input[$key] ?? null) ? trim($input[$key]) : '';

        $type = array_key_exists($string('tipo'), self::TYPES) ? $string('tipo') : self::TYPE_ALL;
        $period = array_key_exists($string('periodo'), self::PERIODS) ? $string('periodo') : self::DEFAULT_PERIOD;
        $company = $string('empresa') === '' ? null : Company::query()->where('slug', $string('empresa'))->first();

        return new self(
            mb_substr($string('q'), 0, self::MAX_SEARCH),
            $type,
            $company,
            $period,
            $period === 'rango' ? self::date($string('desde')) : null,
            $period === 'rango' ? self::date($string('hasta')) : null,
        );
    }

    /**
     * First moment included, in the app timezone; null for no lower bound.
     */
    public function start(): ?CarbonImmutable
    {
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();

        return match ($this->period) {
            'hoy' => $today,
            'rango' => $this->from?->startOfDay(),
            default => $today->subDays((int) $this->period - 1),
        };
    }

    /**
     * Last moment included; null for no upper bound.
     */
    public function end(): ?CarbonImmutable
    {
        return $this->period === 'rango' ? $this->to?->endOfDay() : null;
    }

    public function includesChanges(): bool
    {
        return in_array($this->type, [self::TYPE_ALL, self::TYPE_CHANGES], true);
    }

    public function includesSecurity(): bool
    {
        return in_array($this->type, [self::TYPE_ALL, self::TYPE_SECURITY], true);
    }

    public function includesLaunches(): bool
    {
        return in_array($this->type, [self::TYPE_ALL, self::TYPE_LAUNCHES], true);
    }

    /**
     * The non-default parameters, for links that keep the current filters.
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        return array_filter([
            'q' => $this->search,
            'tipo' => $this->type === self::TYPE_ALL ? '' : $this->type,
            'empresa' => (string) $this->company?->slug,
            'periodo' => $this->period === self::DEFAULT_PERIOD ? '' : $this->period,
            'desde' => (string) $this->from?->toDateString(),
            'hasta' => (string) $this->to?->toDateString(),
        ], fn (string $value) => $value !== '');
    }

    private static function date(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, config('app.timezone'));
        } catch (Throwable) {
            return null;
        }

        return $date !== null && $date->toDateString() === $value ? $date : null;
    }
}
