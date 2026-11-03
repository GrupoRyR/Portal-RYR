<?php

namespace App\News;

use RuntimeException;

/**
 * Why a news source could not be read, as a short code that is stored in
 * `news_sources.last_error_code` and shown in Admin › Noticias. The code is
 * never a message, a header or part of the response body.
 */
final class FeedFailure extends RuntimeException
{
    public const INVALID_URL = 'invalid_url';

    public const DNS_FAILED = 'dns_failed';

    public const BLOCKED_ADDRESS = 'blocked_address';

    public const TOO_MANY_REDIRECTS = 'too_many_redirects';

    public const CONNECTION_FAILED = 'connection_failed';

    public const TIMEOUT = 'timeout';

    public const TOO_LARGE = 'too_large';

    public const DOCTYPE = 'doctype';

    public const INVALID_XML = 'invalid_xml';

    public const UNSUPPORTED_FORMAT = 'unsupported_format';

    public const UNEXPECTED = 'unexpected';

    public function __construct(public readonly string $errorCode)
    {
        parent::__construct("News feed refused: {$errorCode}");
    }

    /**
     * `http_404`, `http_503`… for a response that is neither a success nor a redirect.
     */
    public static function status(int $status): self
    {
        return new self('http_'.$status);
    }
}
