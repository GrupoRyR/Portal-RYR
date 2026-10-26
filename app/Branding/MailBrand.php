<?php

namespace App\Branding;

use App\Models\Company;

/**
 * The brand of a portal email, passed to the mail views as `brand`. Email
 * clients ignore CSS variables, so the views print these colors inline;
 * they all come from BrandPalette, which only holds "#RRGGBB" values.
 * - dark: band behind the logo (white text on it);
 * - button: background of the action button (white text on it);
 * - ink: brand text and links on white.
 */
final readonly class MailBrand
{
    public function __construct(
        public string $name,
        public ?string $logoUrl,
        public bool $logoOnDark,
        public string $dark,
        public string $button,
        public string $ink,
    ) {}

    public static function for(?Company $company): self
    {
        $palette = BrandPalette::forCompany($company);
        $logo = CompanyLogo::for($company);

        return new self(
            name: Company::displayName($company),
            logoUrl: $logo === null ? null : $company?->brandFileUrl($logo->path),
            logoOnDark: $logo?->onDark ?? false,
            dark: $palette->dark,
            button: $palette->button,
            ink: $palette->ink,
        );
    }
}
