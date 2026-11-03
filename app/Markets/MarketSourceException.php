<?php

namespace App\Markets;

use RuntimeException;

/**
 * A market source gave nothing usable. `reason` is a short code that is
 * safe to log (`http_503`, `connection`, `too_large`, `doctype`…): never
 * the response, which may echo anything.
 */
final class MarketSourceException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
