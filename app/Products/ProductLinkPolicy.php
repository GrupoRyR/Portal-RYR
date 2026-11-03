<?php

namespace App\Products;

use App\Models\Product;

/**
 * Which links a product may carry (a datasheet, a safety data sheet...):
 * `https://` only, on a plain DNS host with at least two labels, with no
 * user info and no explicit port.
 *
 * App\Catalog\AppUrlPolicy is not used here on purpose: it admits only the
 * hosts of the app allow-list (Configuración), and a datasheet can live on
 * a supplier's site.
 */
final class ProductLinkPolicy
{
    public static function allows(?string $url): bool
    {
        if ($url === null || $url === '' || strlen($url) > Product::LINK_MAX) {
            return false;
        }

        if ($url !== trim($url) || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || ! isset($parts['host'])) {
            return false;
        }

        // parse_url() is lenient: the authority must be exactly the host.
        $authority = strtolower((string) strtok(substr($url, strlen('https://')), '/?#'));
        $host = strtolower($parts['host']);

        if ($authority !== $host) {
            return false;
        }

        $labels = explode('.', $host);

        if (count($labels) < 2) {
            return false;
        }

        foreach ($labels as $label) {
            if (preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/', $label) !== 1) {
                return false;
            }
        }

        // An all-digit last label is an IPv4 address, not a domain name.
        return preg_match('/\A\d+\z/', end($labels)) !== 1;
    }
}
