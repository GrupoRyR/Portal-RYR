<?php

namespace App\Markets;

/**
 * What one run of App\Markets\MarketSync did, by series code: the rows it
 * saved, the short code of why it could not, or why it did not try
 * (`no_key`: FRED without a key; `fresh`: a monthly series already read
 * today). A skipped series is not a failure.
 */
final readonly class MarketSyncResult
{
    /**
     * @param  array<string, int>  $saved  Rows written, by series code.
     * @param  array<string, string>  $failed  Short failure code, by series code.
     * @param  array<string, string>  $skipped  Why it was not read (`no_key`, `fresh`), by series code.
     */
    public function __construct(
        public array $saved,
        public array $failed,
        public array $skipped = [],
    ) {}

    public function failed(): bool
    {
        return $this->failed !== [];
    }
}
