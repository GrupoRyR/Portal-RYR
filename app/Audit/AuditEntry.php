<?php

namespace App\Audit;

use Carbon\CarbonImmutable;

/**
 * One row of Auditoría, whatever its source (an activity log entry or an
 * app launch), already in the words shown to the admin.
 */
final readonly class AuditEntry
{
    /** Source of the row; also its tie-break order in the feed. */
    public const KIND_ACTIVITY = 1;

    public const KIND_LAUNCH = 2;

    public const CATEGORY_CHANGE = 'change';

    public const CATEGORY_LAUNCH = 'launch';

    public const CATEGORY_SECURITY = 'security';

    public const CATEGORY_LABELS = [
        self::CATEGORY_CHANGE => 'Cambio',
        self::CATEGORY_LAUNCH => 'Abrió app',
        self::CATEGORY_SECURITY => 'Seguridad',
    ];

    public const DEVICE_LABELS = [
        'desktop' => 'Escritorio',
        'tablet' => 'Tablet',
        'phone' => 'Celular',
    ];

    /**
     * @param  string  $who  Person's name, or «Sistema».
     * @param  list<array{label: string, old: string, new: string}>  $changes  Before/after of a change.
     */
    public function __construct(
        public int $kind,
        public int $id,
        public CarbonImmutable $occurredAt,
        public string $who,
        public ?string $email,
        public string $category,
        public string $what,
        public ?string $company,
        public ?string $device,
        public array $changes = [],
    ) {}

    public function categoryLabel(): string
    {
        return self::CATEGORY_LABELS[$this->category];
    }

    /**
     * Device type of an app launch; «—» for everything else.
     */
    public function deviceLabel(): string
    {
        return $this->device === null ? '—' : (self::DEVICE_LABELS[$this->device] ?? $this->device);
    }

    /**
     * «hace 5 minutos» within the last 24 hours, then a Bogotá date.
     */
    public function when(): string
    {
        $local = $this->occurredAt->timezone(config('app.timezone'))->locale('es');

        return $local->greaterThan(CarbonImmutable::now()->subDay())
            ? $local->diffForHumans()
            : $local->isoFormat('D MMM YYYY, HH:mm');
    }
}
