<?php

namespace App\Microsoft;

/**
 * The parts of Microsoft Graph the portal reads with the signed-in user's
 * delegated access token. Implementations throw on any failure.
 */
interface GraphClient
{
    /**
     * @return array{display_name: string|null, job_title: string|null, mail: string|null}
     */
    public function profile(string $accessToken): array;

    /**
     * Every group the user belongs to, directly or through nested groups.
     * `security_group`: a security group, not a Microsoft 365 group.
     *
     * @return list<array{id: string, display_name: string, security_group: bool}>
     */
    public function groups(string $accessToken): array;
}
