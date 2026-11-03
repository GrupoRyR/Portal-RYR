<?php

namespace App\Markets;

/**
 * What one run of App\Markets\MarketSync did, by source (`trm`, `eur`):
 * the rows it saved, or the short code of why it could not.
 */
final readonly class MarketSyncResult
{
    /**
     * @param  array<string, int>  $saved  Rows written, by source.
     * @param  array<string, string>  $failed  Short failure code, by source.
     */
    public function __construct(
        public array $saved,
        public array $failed,
    ) {}

    public function failed(): bool
    {
        return $this->failed !== [];
    }
}
