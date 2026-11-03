<?php

namespace App\ItStatus;

/**
 * «Estado de TI» of one person's active company (App\ItStatus\ItStatusBoard).
 */
final readonly class ItStatus
{
    /**
     * @param  bool  $available  false for people without an active company
     *                           (and the emergency account): nothing to show
     * @param  list<ItStatusItem>  $apps  in catalog order
     * @param  list<ItStatusItem>  $services  worst first, then in admin order
     * @param  string|null  $reportHref  «Reportar un problema» (/soporte), null
     *                                   when no support URL is configured
     */
    public function __construct(
        public bool $available,
        public ItStatusLevel $level,
        public array $apps,
        public array $services,
        public ?string $reportHref,
    ) {}

    public function allGood(): bool
    {
        return $this->level === ItStatusLevel::Operational;
    }
}
