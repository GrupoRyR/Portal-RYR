<?php

namespace App\Mail;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * App-only Microsoft Graph token for sending mail (OAuth 2.0 client
 * credentials against the mail tenant), cached until shortly before it
 * expires so a burst of messages costs a single token request.
 *
 * App\Microsoft\ConsentChecker also asks for an app-only token, but as a
 * probe of another tenant's consent: it needs the raw error codes and must
 * never cache. This class only needs a usable token or a clear failure.
 *
 * The token and the client secret never appear in exception messages, logs
 * or the cache key.
 *
 * @see https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-client-creds-grant-flow
 */
class GraphAccessToken
{
    /** Renew this many seconds before Entra says the token expires. */
    public const EXPIRY_MARGIN = 300;

    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
        private readonly string $tenant,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly int $timeout = 15,
    ) {}

    public function get(): string
    {
        $cached = $this->cache->get($this->cacheKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = $this->http->asForm()
                ->timeout($this->timeout)
                ->post('https://login.microsoftonline.com/'.rawurlencode($this->tenant).'/oauth2/v2.0/token', [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                ]);
        } catch (ConnectionException) {
            // No previous exception: its message may echo the request.
            throw new TransportException('Microsoft sign-in (login.microsoftonline.com) could not be reached.');
        }

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            $error = $response->json('error');
            $codes = array_filter((array) $response->json('error_codes', []), 'is_numeric');

            throw new TransportException(sprintf(
                'Microsoft token request failed: HTTP %d (%s%s).',
                $response->status(),
                is_string($error) && $error !== '' ? $error : 'unknown_error',
                $codes === [] ? '' : ', AADSTS'.implode(', AADSTS', $codes),
            ));
        }

        $ttl = (int) $response->json('expires_in', 0) - self::EXPIRY_MARGIN;

        if ($ttl > 0) {
            $this->cache->put($this->cacheKey(), $token, $ttl);
        }

        return $token;
    }

    /**
     * Drop the cached token, e.g. after Graph rejected it with a 401.
     */
    public function forget(): void
    {
        $this->cache->forget($this->cacheKey());
    }

    private function cacheKey(): string
    {
        return 'microsoft:mail-token:'.strtolower($this->tenant).':'.strtolower($this->clientId);
    }
}
