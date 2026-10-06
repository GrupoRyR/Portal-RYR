<?php

namespace App\View\Home;

use App\Models\Announcement;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * One announcement as the home screen, the /anuncios page and the admin
 * preview print it (resources/views/components/announcements/*). The text
 * is plain: the views escape it and keep its line breaks.
 */
final readonly class AnnouncementCard
{
    public function __construct(
        public ?int $id,
        public string $title,
        public string $body,
        public ?string $imageUrl,
        public bool $pinned,
        public string $date,
    ) {}

    /**
     * @param  string|null  $imageUrl  Overrides the stored image (admin preview of a new upload).
     */
    public static function from(Announcement $announcement, ?string $imageUrl = null, ?CarbonInterface $now = null): self
    {
        return new self(
            id: $announcement->exists ? $announcement->id : null,
            title: (string) $announcement->title,
            body: (string) $announcement->body,
            imageUrl: $imageUrl ?? $announcement->imageUrl(),
            pinned: (bool) $announcement->is_pinned,
            date: self::date($announcement->starts_at ?? $now ?? Carbon::now(), $now ?? Carbon::now()),
        );
    }

    /**
     * «5 de octubre», with the year when it is not the current one, in
     * Bogotá time.
     */
    public static function date(CarbonInterface $moment, CarbonInterface $now): string
    {
        $local = $moment->toImmutable()->setTimezone(Greeting::TIMEZONE)->locale('es');
        $sameYear = $local->year === $now->toImmutable()->setTimezone(Greeting::TIMEZONE)->year;

        return $local->isoFormat($sameYear ? 'D [de] MMMM' : 'D [de] MMMM [de] YYYY');
    }

    /** Where the phone/tablet banner leads: the announcement on /anuncios. */
    public function href(): ?string
    {
        return $this->id === null ? null : route('announcements.index').'#a-'.$this->id;
    }
}
