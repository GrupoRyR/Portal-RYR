<?php

namespace App\Microsoft;

use App\Mail\GraphAccessToken;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;

/**
 * App-only Microsoft Graph token for any tenant of the group (OAuth 2.0
 * client credentials with the portal's own registration,
 * `services.microsoft.client_id`/`client_secret`), cached per tenant and
 * client id until shortly before it expires. The directory copy
 * (App\Directory\DirectorySync) reads each company's people with it.
 *
 * App\Mail\GraphAccessToken does the same for the one mail tenant and
 * fails as a mail transport error; App\Microsoft\ConsentChecker only
 * probes consent and never caches. This one fails with GraphAppException.
 *
 * The token and the client secret never appear in exception messages, logs
 * or the cache key.
 *
 * @see https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-client-creds-grant-flow
 */
class AppOnlyToken
{
    /** Renew this many seconds before Entra says the token expires. */
    public const EXPIRY_MARGIN = GraphAccessToken::EXPIRY_MARGIN;

    private const TIMEOUT = 15;

    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
    ) {}

    /**
     * @throws GraphAppException
     */
    public function for(string $tenantId): string
    {
        $tenantId = self::normalize($tenantId);
        $clientId = (string) config('services.microsoft.client_id');
        $secret = (string) config('services.microsoft.client_secret');

        if ($tenantId === '' || $clientId === '' || $secret === '') {
            throw new GraphAppException('The Microsoft client id or secret is not configured, or the tenant is empty.');
        }

        $cached = $this->cache->get(self::cacheKey($tenantId, $clientId));

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = $this->http->asForm()
                ->timeout(self::TIMEOUT)
                ->post('https://login.microsoftonline.com/'.rawurlencode($tenantId).'/oauth2/v2.0/token', [
                    'client_id' => $clientId,
                    'client_secret' => $secret,
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                ]);
        } catch (ConnectionException) {
            // No previous exception: its message may echo the request.
            throw new GraphAppException('Microsoft sign-in (login.microsoftonline.com) could not be reached.');
        }

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            $error = $response->json('error');
            $codes = array_values(array_map('intval', array_filter((array) $response->json('error_codes', []), 'is_numeric')));

            throw new GraphAppException(sprintf(
                'Microsoft token request failed: HTTP %d (%s%s).',
                $response->status(),
                is_string($error) && preg_match('/\A[a-z_]+\z/', $error) === 1 ? $error : 'unknown_error',
                $codes === [] ? '' : ', AADSTS'.implode(', AADSTS', $codes),
            ), $response->status(), $codes);
        }

        $ttl = (int) $response->json('expires_in', 0) - self::EXPIRY_MARGIN;

        if ($ttl > 0) {
            $this->cache->put(self::cacheKey($tenantId, $clientId), $token, $ttl);
        }

        return $token;
    }

    /**
     * Drop the cached token of a tenant, e.g. after Graph rejected it with a 401.
     */
    public function forget(string $tenantId): void
    {
        $this->cache->forget(self::cacheKey(self::normalize($tenantId), (string) config('services.microsoft.client_id')));
    }

    private static function cacheKey(string $tenantId, string $clientId): string
    {
        return 'microsoft:app-token:'.$tenantId.':'.strtolower($clientId);
    }

    private static function normalize(string $tenantId): string
    {
        return strtolower(trim($tenantId));
    }
}
