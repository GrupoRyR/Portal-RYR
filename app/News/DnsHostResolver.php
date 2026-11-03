<?php

namespace App\News;

/**
 * The system resolver: A and AAAA records (CNAMEs are followed by the
 * resolver itself). Falls back to gethostbynamel() when dns_get_record()
 * gives nothing, as on some shared hosts.
 */
final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $addresses = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        foreach (is_array($records) ? $records : [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                $addresses[] = $ip;
            }
        }

        if ($addresses === []) {
            $addresses = @gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($addresses));
    }
}
