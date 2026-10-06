<?php

namespace App\Microsoft;

/**
 * The parts of Microsoft Graph the portal reads with the signed-in user's
 * delegated access token. Implementations throw on any failure other than
 * "the user has no photo".
 */
interface GraphClient
{
    /**
     * @return array{display_name: string|null, job_title: string|null, mail: string|null}
     */
    public function profile(string $accessToken): array;

    /**
     * The user's photo bytes, or null when the user has no photo.
     */
    public function photo(string $accessToken): ?string;

    /**
     * Every group the user belongs to, directly or through nested groups.
     *
     * @return list<array{id: string, display_name: string}>
     */
    public function groups(string $accessToken): array;
}
