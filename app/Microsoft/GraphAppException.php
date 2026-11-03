<?php

namespace App\Microsoft;

use RuntimeException;

/**
 * An app-only call to Microsoft (token or Graph) that failed. It carries
 * the HTTP status and the AADSTS codes Entra gave, never the token, the
 * client secret or a previous exception (whose message may echo the
 * request).
 */
final class GraphAppException extends RuntimeException
{
    /**
     * @param  int|null  $status  HTTP status; null when Microsoft could not be reached.
     * @param  list<int>  $aadsts  AADSTS error codes of a refused token.
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly array $aadsts = [],
    ) {
        parent::__construct($message);
    }
}
