<?php

namespace App\News;

/**
 * Whether the news fetcher may connect to an IP address: only globally
 * routable unicast addresses. Everything a request could use to reach the
 * server itself or the hosting network is refused: loopback, private,
 * link-local (cloud metadata), CGNAT, multicast, reserved, documentation
 * and benchmarking ranges, and the IPv6 forms that embed an IPv4 address
 * (IPv4-mapped, IPv4-compatible, NAT64, 6to4, Teredo).
 *
 * The ranges are listed here rather than left to filter_var() flags, whose
 * coverage changed between PHP versions.
 */
final class AddressPolicy
{
    /** @var list<string> */
    private const BLOCKED_RANGES = [
        // IPv4 (RFC 6890 special-purpose registry).
        '0.0.0.0/8',          // "this network"
        '10.0.0.0/8',         // private
        '100.64.0.0/10',      // CGNAT (shared address space)
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local, cloud metadata
        '172.16.0.0/12',      // private
        '192.0.0.0/24',       // IETF protocol assignments
        '192.0.2.0/24',       // documentation (TEST-NET-1)
        '192.88.99.0/24',     // 6to4 relay anycast
        '192.168.0.0/16',     // private
        '198.18.0.0/15',      // benchmarking
        '198.51.100.0/24',    // documentation (TEST-NET-2)
        '203.0.113.0/24',     // documentation (TEST-NET-3)
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved, includes 255.255.255.255
        // IPv6.
        '::/96',              // unspecified, loopback and IPv4-compatible
        '::ffff:0:0/96',      // IPv4-mapped
        '64:ff9b::/96',       // NAT64 well-known prefix
        '64:ff9b:1::/48',     // NAT64 local use
        '100::/64',           // discard
        '2001::/23',          // IETF protocol assignments, includes Teredo
        '2001:db8::/32',      // documentation
        '2002::/16',          // 6to4
        '3fff::/20',          // documentation (RFC 9637)
        'fc00::/7',           // unique local
        'fe80::/10',          // link-local
        'fec0::/10',          // site-local (deprecated)
        'ff00::/8',           // multicast
    ];

    public static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $address = inet_pton($ip);

        if ($address === false) {
            return false;
        }

        foreach (self::BLOCKED_RANGES as $range) {
            if (self::inRange($address, $range)) {
                return false;
            }
        }

        // IPv6 outside 2000::/3 is not global unicast yet.
        if (strlen($address) === 16 && (ord($address[0]) & 0xE0) !== 0x20) {
            return false;
        }

        return true;
    }

    private static function inRange(string $address, string $range): bool
    {
        [$network, $bits] = explode('/', $range);
        $network = inet_pton($network);

        if ($network === false || strlen($network) !== strlen($address)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);

        if (strncmp($address, $network, $bytes) !== 0) {
            return false;
        }

        $remaining = $bits % 8;

        if ($remaining === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remaining)) & 0xFF;

        return (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }
}
