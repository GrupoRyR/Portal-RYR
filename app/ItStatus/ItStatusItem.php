<?php

namespace App\ItStatus;

use Carbon\CarbonImmutable;

/**
 * One line of «Estado de TI»: an app in maintenance or with a current
 * failure report, or a manual service that is not operational.
 */
final readonly class ItStatusItem
{
    public const KIND_APP = 'app';

    public const KIND_SERVICE = 'service';

    /**
     * @param  string  $label  chip text: «En mantenimiento», «Con fallas», «Fuera de servicio»
     * @param  CarbonImmutable|null  $until  end of a maintenance window, when the admin gave one
     * @param  string|null  $slug  the app's fixed identifier (null for a service)
     */
    public function __construct(
        public string $kind,
        public string $name,
        public ItStatusLevel $level,
        public string $label,
        public ?string $message = null,
        public ?CarbonImmutable $until = null,
        public ?string $slug = null,
    ) {}
}
