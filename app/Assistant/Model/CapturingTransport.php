<?php

namespace App\Assistant\Model;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that keeps the body of the last response, so the
 * Anthropic driver can read the tool inputs exactly as the API wrote them
 * (a nested `{}` stays an object; the SDK's PHP arrays cannot tell it from
 * `[]`). The body is rewound, so the SDK reads it as usual.
 */
final class CapturingTransport implements ClientInterface
{
    private ?string $lastBody = null;

    public function __construct(private readonly ClientInterface $inner) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = $this->inner->sendRequest($request);
        $body = $response->getBody();

        if ($body->isSeekable()) {
            $this->lastBody = (string) $body;
            $body->rewind();
        }

        return $response;
    }

    /**
     * The last response as JSON objects (stdClass), or null.
     */
    public function lastJson(): ?object
    {
        $decoded = $this->lastBody === null ? null : json_decode($this->lastBody, false);

        return is_object($decoded) ? $decoded : null;
    }
}
