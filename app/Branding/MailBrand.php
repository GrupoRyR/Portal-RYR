<?php

namespace App\Branding;

use App\Models\Company;

/**
 * The brand of a portal email, passed to the mail views as `brand`. Email
 * clients ignore CSS variables, so the views print these colors inline;
 * they all come from BrandPalette, which only holds "#RRGGBB" values.
 * - dark: band behind the logo (white text on it);
 * - button: background of the action button (white text on it);
 * - ink: the greeting and links on white (AA on white, BrandPalette).
 */
final readonly class MailBrand
{
    /** Height of the logo in the header band, in CSS pixels. */
    public const LOGO_HEIGHT = 28;

    /**
     * @param  int|null  $logoWidth  Width at LOGO_HEIGHT, from the file's own
     *                               proportions; null when unknown.
     */
    public function __construct(
        public string $name,
        public ?string $logoUrl,
        public bool $logoOnDark,
        public ?int $logoWidth,
        public string $dark,
        public string $button,
        public string $ink,
    ) {}

    public static function for(?Company $company): self
    {
        $palette = BrandPalette::forCompany($company);
        $logo = CompanyLogo::for($company);
        $size = $logo === null ? null : $company?->brandImageSize($logo->path);

        return new self(
            name: Company::displayName($company),
            logoUrl: $logo === null ? null : $company?->brandFileUrl($logo->path),
            logoOnDark: $logo?->onDark ?? false,
            logoWidth: $size === null ? null : max(1, (int) round($size[0] * self::LOGO_HEIGHT / $size[1])),
            dark: $palette->dark,
            button: $palette->button,
            ink: $palette->ink,
        );
    }
}
