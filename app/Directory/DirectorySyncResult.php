<?php

namespace App\Directory;

/**
 * What one run of App\Directory\DirectorySync did.
 */
final readonly class DirectorySyncResult
{
    /**
     * @param  array<int, array{name: string, saved: int, removed: int}>  $companies  Per company id, the active companies of the tenants copied: people saved and entries removed.
     * @param  list<string>  $failedTenants  Tenants that could not be read; their entries were kept.
     */
    public function __construct(
        public array $companies,
        public array $failedTenants,
    ) {}

    public function failed(): bool
    {
        return $this->failedTenants !== [];
    }
}
