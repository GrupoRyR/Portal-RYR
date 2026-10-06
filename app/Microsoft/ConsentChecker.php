<?php

namespace App\Microsoft;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Detects whether a tenant's administrator granted admin consent to the
 * portal's app registration.
 *
 * Admin consent creates the app's service principal in that tenant. With
 * it, an app-only token request (client credentials, Microsoft Graph
 * /.default) succeeds; without it, Entra answers with an AADSTS error saying
 * the app is not installed/consented there.
 *
 * @see https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-client-creds-grant-flow
 * @see https://learn.microsoft.com/en-us/entra/identity-platform/reference-error-codes
 */
class ConsentChecker
{
    public const GRANTED = 'granted';

    public const PENDING = 'pending';

    public const UNKNOWN = 'unknown';

    /**
     * AADSTS codes meaning "not installed / not consented in this tenant":
     * 700016 UnauthorizedClient_DoesNotMatchRequest (application not found
     * in the directory/tenant), 65001 DelegationDoesNotExist (no consent),
     * 500011 InvalidResourceServicePrincipalNotFound (not installed).
     * Anything else (bad or expired secret, unknown tenant, Conditional
     * Access, outages) is "unknown": it never proves consent either way.
     */
    public const PENDING_CODES = [700016, 65001, 500011];

    /**
     * State sent with the admin-consent link. Sign-in states are random
     * 40-character strings, so the shared callback can tell them apart.
     */
    public const ADMIN_CONSENT_STATE = 'admin-consent';

    private const GUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    public function __construct(private readonly Http $http) {}

    public function check(string $tenantId): string
    {
        $tenantId = strtolower(trim($tenantId));
        $clientId = (string) config('services.microsoft.client_id');
        $secret = (string) config('services.microsoft.client_secret');

        if (preg_match(self::GUID, $tenantId) !== 1) {
            return self::UNKNOWN;
        }

        if ($clientId === '' || $secret === '') {
            Log::warning('Consent check skipped: the Microsoft client id or secret is not configured.', ['tenant_id' => $tenantId]);

            return self::UNKNOWN;
        }

        try {
            $response = $this->http->asForm()
                ->timeout(10)
                ->post("https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token", [
                    'client_id' => $clientId,
                    'client_secret' => $secret,
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                ]);
        } catch (Throwable $e) {
            // Only the class: messages of HTTP exceptions can echo the request.
            Log::warning('Consent check failed: Microsoft could not be reached.', ['tenant_id' => $tenantId, 'exception' => $e::class]);

            return self::UNKNOWN;
        }

        // The token itself is never stored or logged.
        if ($response->successful() && is_string($response->json('access_token')) && $response->json('access_token') !== '') {
            return self::GRANTED;
        }

        $codes = $this->errorCodes($response->json());

        if (array_intersect($codes, self::PENDING_CODES) !== []) {
            return self::PENDING;
        }

        Log::warning('Consent check inconclusive.', ['tenant_id' => $tenantId, 'status' => $response->status(), 'aadsts' => $codes]);

        return self::UNKNOWN;
    }

    /**
     * Link a tenant administrator opens once to grant admin consent. The
     * redirect URI is required by the v2 endpoint and must exactly match a
     * registered one, so the app's sign-in callback is used; the fixed
     * state lets that callback recognise the answer (see ADMIN_CONSENT_STATE).
     */
    public function adminConsentUrl(string $tenantId): string
    {
        return 'https://login.microsoftonline.com/'.rawurlencode(strtolower($tenantId)).'/adminconsent?'.http_build_query([
            'client_id' => (string) config('services.microsoft.client_id'),
            'redirect_uri' => (string) config('services.microsoft.redirect'),
            'state' => self::ADMIN_CONSENT_STATE,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return list<int>
     */
    private function errorCodes(mixed $body): array
    {
        if (! is_array($body)) {
            return [];
        }

        $codes = array_map('intval', array_filter((array) ($body['error_codes'] ?? []), 'is_numeric'));

        if (preg_match_all('/AADSTS(\d+)/', (string) ($body['error_description'] ?? ''), $matches) > 0) {
            $codes = [...$codes, ...array_map('intval', $matches[1])];
        }

        return array_values(array_unique($codes));
    }
}
