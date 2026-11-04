<?php

namespace App\Assistant\Model;

use RuntimeException;
use Throwable;

/**
 * The model could not answer. `reason` is a short code for Auditoría
 * (`timeout`, `connection`, `rate_limited`, `overloaded`, `auth`, `http_400`,
 * `not_configured`, `unexpected`), never the API's message.
 */
final class ModelUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct("The assistant model is unavailable ({$reason}).", 0, $previous);
    }
}
