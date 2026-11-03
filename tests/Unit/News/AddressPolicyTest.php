<?php

namespace Tests\Unit\News;

use App\News\AddressPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AddressPolicyTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function blockedAddresses(): array
    {
        return [
            'this network' => ['0.0.0.0'],
            'this network range' => ['0.1.2.3'],
            'loopback' => ['127.0.0.1'],
            'loopback range' => ['127.255.0.9'],
            'private 10/8' => ['10.0.0.5'],
            'private 172.16/12' => ['172.16.0.1'],
            'private 172.31' => ['172.31.255.255'],
            'private 192.168/16' => ['192.168.1.10'],
            'link-local' => ['169.254.169.254'],
            'cgnat' => ['100.64.0.1'],
            'cgnat top' => ['100.127.255.254'],
            'ietf protocol assignments' => ['192.0.0.8'],
            'documentation 1' => ['192.0.2.1'],
            'benchmarking' => ['198.18.0.1'],
            'documentation 2' => ['198.51.100.1'],
            'documentation 3' => ['203.0.113.1'],
            'multicast' => ['224.0.0.1'],
            'multicast top' => ['239.255.255.250'],
            'reserved' => ['240.0.0.1'],
            'broadcast' => ['255.255.255.255'],
            'ipv6 unspecified' => ['::'],
            'ipv6 loopback' => ['::1'],
            'ipv4-mapped loopback' => ['::ffff:127.0.0.1'],
            'ipv4-mapped public' => ['::ffff:8.8.8.8'],
            'ipv4-mapped hex' => ['::ffff:7f00:1'],
            'ipv4-compatible' => ['::127.0.0.1'],
            'nat64' => ['64:ff9b::a00:1'],
            'discard' => ['100::1'],
            'teredo' => ['2001::1'],
            'ipv6 documentation' => ['2001:db8::1'],
            '6to4' => ['2002:7f00:1::1'],
            'unique local' => ['fd00::1'],
            'unique local fc' => ['fc00::1'],
            'ipv6 link-local' => ['fe80::1'],
            'ipv6 site-local' => ['fec0::1'],
            'ipv6 multicast' => ['ff02::1'],
            'not an address' => ['example.com'],
            'empty' => [''],
            'with zone' => ['fe80::1%eth0'],
        ];
    }

    #[DataProvider('blockedAddresses')]
    public function test_non_public_addresses_are_refused(string $ip): void
    {
        $this->assertFalse(AddressPolicy::isPublic($ip));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function publicAddresses(): array
    {
        return [
            'google dns' => ['8.8.8.8'],
            'just below private 172' => ['172.15.255.255'],
            'just above private 172' => ['172.32.0.1'],
            'just below cgnat' => ['100.63.255.255'],
            'just above cgnat' => ['100.128.0.1'],
            'ipv6 global' => ['2606:4700:4700::1111'],
            'ipv6 global 2' => ['2800:3f0:4005:40b::200e'],
        ];
    }

    #[DataProvider('publicAddresses')]
    public function test_public_addresses_are_allowed(string $ip): void
    {
        $this->assertTrue(AddressPolicy::isPublic($ip));
    }
}
