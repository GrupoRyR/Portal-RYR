<?php

namespace App\Pwa;

/**
 * Public URLs of the icons the installed app uses: "any" icons for the
 * launcher and splash screen, a maskable one that launchers may crop to
 * any shape, and the Apple touch icon (iOS ignores the manifest icons).
 */
final readonly class PwaIconSet
{
    public function __construct(
        public string $any192,
        public string $any512,
        public string $maskable512,
        public string $apple180,
    ) {}

    /**
     * The neutral portal icons in public/icons (signed out, a company
     * without an icon, or an icon that could not be converted).
     */
    public static function portal(): self
    {
        return new self(
            any192: asset('icons/portal-192.png'),
            any512: asset('icons/portal-512.png'),
            maskable512: asset('icons/portal-maskable-512.png'),
            apple180: asset('icons/portal-apple-180.png'),
        );
    }
}
