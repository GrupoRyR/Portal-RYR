<?php

namespace App\View\Home;

use App\Branding\HexColor;
use App\Catalog\AppSearch;
use App\Models\App;
use Illuminate\Support\Str;

/**
 * Everything one app card on the home screen shows, already decided.
 */
final readonly class AppCard
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $name,
        public string $description,
        public ?string $iconUrl,
        public ?string $iconDarkUrl,
        public string $accentColor,
        public string $initial,
        public string $categorySlug,
        public string $categoryName,
        public int $position,
        public string $href,
        public bool $opensInNewTab,
        public AppCardStatus $status,
        public ?string $statusDetail,
        public bool $favorite,
        public string $searchText,
    ) {}

    /**
     * @param  int  $position  place in catalog order, so a card can go back to
     *                         the right spot of its category without a reload
     */
    public static function from(App $app, int $position, bool $favorite): self
    {
        $status = AppCardStatus::of($app);

        return new self(
            id: $app->id,
            slug: $app->slug,
            name: $app->name,
            description: $app->description,
            iconUrl: $app->icon_path ? asset($app->icon_path) : null,
            iconDarkUrl: $app->icon_dark_path ? asset($app->icon_dark_path) : null,
            accentColor: HexColor::tryFrom($app->accent_color)?->toHex() ?? '#334155',
            initial: Str::upper(mb_substr($app->name, 0, 1)),
            categorySlug: (string) $app->category?->slug,
            categoryName: (string) $app->category?->name,
            position: $position,
            href: route('apps.open', $app),
            // Local apps open an info page and maintenance apps a notice:
            // both stay in the portal's tab.
            opensInNewTab: $app->open_in === App::OPEN_NEW && $app->isWeb() && ! $app->inMaintenance(),
            status: $status,
            statusDetail: $status === AppCardStatus::Failing
                ? ($app->external_status_message ?: 'Estamos revisando')
                : null,
            favorite: $favorite,
            searchText: AppSearch::normalize($app->name.' '.$app->description),
        );
    }
}
