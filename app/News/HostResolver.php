<?php

namespace App\News;

use Illuminate\Container\Attributes\Bind;

/**
 * Resolves a host name to its IP addresses for App\News\FeedFetcher. Tests
 * bind a fake so they never resolve real names.
 */
#[Bind(DnsHostResolver::class)]
interface HostResolver
{
    /**
     * Every IPv4 and IPv6 address of the host; empty when it does not resolve.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
