<?php

namespace App\Support;

/**
 * Coarse device type from a User-Agent, for the launch log. Only the
 * result is stored, never the User-Agent itself.
 *
 * iPads on iPadOS 13+ send a desktop Safari User-Agent by default and are
 * therefore counted as `desktop`; that is accepted for a usage report.
 */
final class DeviceClassifier
{
    public const DESKTOP = 'desktop';

    public const TABLET = 'tablet';

    public const PHONE = 'phone';

    public static function classify(?string $userAgent): string
    {
        $userAgent = (string) $userAgent;

        if ($userAgent === '') {
            return self::DESKTOP;
        }

        if (preg_match('/iPad|Tablet|PlayBook|Kindle|Silk\//i', $userAgent) === 1
            || (stripos($userAgent, 'Android') !== false && stripos($userAgent, 'Mobile') === false)) {
            return self::TABLET;
        }

        if (preg_match('/Mobi|iPhone|iPod|Windows Phone|BlackBerry|Opera Mini/i', $userAgent) === 1) {
            return self::PHONE;
        }

        return self::DESKTOP;
    }
}
