<?php

namespace App\Microsoft;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Microsoft Graph v1.0 over Laravel's HTTP client.
 */
class HttpGraphClient implements GraphClient
{
    private const BASE_URL = 'https://graph.microsoft.com/v1.0';

    /**
     * Upper bound on followed `@odata.nextLink` pages, so a misbehaving
     * response can never loop forever.
     */
    private const MAX_PAGES = 50;

    public function profile(string $accessToken): array
    {
        $me = $this->request($accessToken)
            ->get(self::BASE_URL.'/me', ['$select' => 'displayName,jobTitle,mail'])
            ->throw()
            ->json();

        return [
            'display_name' => $me['displayName'] ?? null,
            'job_title' => $me['jobTitle'] ?? null,
            'mail' => $me['mail'] ?? null,
        ];
    }

    public function photo(string $accessToken): ?string
    {
        $response = $this->request($accessToken)
            ->accept('image/*')
            ->get(self::BASE_URL.'/me/photo/$value');

        if ($response->notFound()) {
            return null;
        }

        return $response->throw()->body();
    }

    public function groups(string $accessToken): array
    {
        $groups = [];
        $url = self::BASE_URL.'/me/transitiveMemberOf/microsoft.graph.group';
        $query = ['$select' => 'id,displayName'];

        for ($page = 0; $url !== null && $page < self::MAX_PAGES; $page++) {
            // A null query keeps the URL's own query string; an empty array
            // would strip it (and the next link's $skiptoken with it).
            $body = $this->request($accessToken)->get($url, $query)->throw()->json();

            foreach ($body['value'] ?? [] as $group) {
                $groups[] = [
                    'id' => (string) ($group['id'] ?? ''),
                    'display_name' => (string) ($group['displayName'] ?? ''),
                ];
            }

            // The next link already carries every query parameter.
            $url = $body['@odata.nextLink'] ?? null;
            $query = null;
        }

        return $groups;
    }

    private function request(string $accessToken): PendingRequest
    {
        return Http::withToken($accessToken)->acceptJson()->timeout(10);
    }
}
