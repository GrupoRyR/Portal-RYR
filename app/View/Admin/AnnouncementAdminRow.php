<?php

namespace App\View\Admin;

use App\Models\Announcement;
use App\Models\Company;
use App\View\Home\AnnouncementCard;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * One row of the announcements list in Administración, already worded in
 * Spanish (docs/diseno/AdminAnnouncements.dc.html). Needs `companies` and
 * `author` loaded.
 */
final readonly class AnnouncementAdminRow
{
    public const STATUS_LABELS = [
        Announcement::STATUS_PUBLISHED => 'Publicado',
        Announcement::STATUS_SCHEDULED => 'Programado',
        Announcement::STATUS_EXPIRED => 'Vencido',
        Announcement::STATUS_WITHDRAWN => 'Retirado',
    ];

    /** Tab labels of the list, by status. */
    public const TAB_LABELS = [
        Announcement::STATUS_PUBLISHED => 'Publicados',
        Announcement::STATUS_SCHEDULED => 'Programados',
        Announcement::STATUS_EXPIRED => 'Vencidos',
        Announcement::STATUS_WITHDRAWN => 'Retirados',
    ];

    public function __construct(
        public Announcement $announcement,
        public string $status,
        public string $statusLabel,
        public string $details,
        public ?string $imageUrl,
    ) {}

    public static function from(Announcement $announcement, ?CarbonInterface $now = null): self
    {
        $now ??= Carbon::now();
        $status = $announcement->status;

        $details = array_filter([
            self::audience($announcement),
            self::dates($announcement, $status, $now),
            $announcement->author ? 'por '.$announcement->author->name : null,
        ]);

        return new self(
            announcement: $announcement,
            status: $status,
            statusLabel: self::STATUS_LABELS[$status],
            details: implode(' · ', $details),
            imageUrl: $announcement->imageUrl(),
        );
    }

    public static function audience(Announcement $announcement): string
    {
        if ($announcement->isForAllCompanies()) {
            return 'Todas las empresas';
        }

        return $announcement->companies
            ->map(fn (Company $company) => $company->short_name ?: $company->name)
            ->sort()
            ->implode(', ') ?: 'Sin empresas';
    }

    private static function dates(Announcement $announcement, string $status, CarbonInterface $now): string
    {
        $date = fn (?CarbonInterface $moment) => $moment === null ? '' : AnnouncementCard::date($moment, $now);

        return match ($status) {
            Announcement::STATUS_SCHEDULED => 'empieza '.$date($announcement->starts_at),
            Announcement::STATUS_EXPIRED => 'terminó '.$date($announcement->ends_at),
            Announcement::STATUS_WITHDRAWN => 'retirado '.$date($announcement->withdrawn_at),
            default => $announcement->ends_at === null
                ? 'desde '.$date($announcement->starts_at)
                : $date($announcement->starts_at).' – '.$date($announcement->ends_at),
        };
    }
}
