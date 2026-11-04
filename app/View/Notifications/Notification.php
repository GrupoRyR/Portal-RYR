<?php

namespace App\View\Notifications;

use App\Models\Announcement;
use App\View\Home\AnnouncementCard;
use App\View\Home\EventDate;
use App\View\Home\WebLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * One line of the bell of the header (App\View\Notifications\NotificationList):
 * its kind (the icon), a title, a short detail and where it leads. Titles
 * come from stored data: the view escapes them. A pending item opens its
 * app in a new tab only through a safe link (WebLink); otherwise it leads
 * to «Tareas pendientes» of the home screen.
 */
final readonly class Notification
{
    public const ANNOUNCEMENT = 'announcement';

    public const APPROVAL = 'approval';

    public const TASK = 'task';

    public const EVENT = 'event';

    public function __construct(
        public string $kind,
        public string $title,
        public ?string $detail,
        public string $href,
        public bool $external = false,
    ) {}

    /**
     * An unread mandatory announcement: «Confirma su lectura · 3 de noviembre».
     */
    public static function announcement(Announcement $announcement, CarbonImmutable $now): self
    {
        return new self(
            kind: self::ANNOUNCEMENT,
            title: (string) $announcement->title,
            detail: 'Confirma su lectura'.($announcement->starts_at === null ? '' : ' · '.AnnouncementCard::date($announcement->starts_at, $now)),
            href: route('announcements.show', $announcement->id),
        );
    }

    /**
     * A pending approval or task: «Aprobación · Vence hoy, 17:00».
     *
     * @param  string|null  $dueLabel  App\View\Home\WorkRow::due()
     */
    public static function work(bool $isApproval, string $title, ?string $url, ?string $dueLabel): self
    {
        $href = WebLink::href($url);

        return new self(
            kind: $isApproval ? self::APPROVAL : self::TASK,
            title: $title,
            detail: ($isApproval ? 'Aprobación' : 'Tarea').($dueLabel === null ? '' : ' · '.$dueLabel),
            href: $href ?? route('home').'#tareas',
            external: $href !== null,
        );
    }

    /**
     * An event of the coming days: «Hoy, 15:00», «Viernes 6 de noviembre» or
     * «En curso · hasta el 7 de noviembre».
     */
    public static function event(EventDate $event): self
    {
        $time = $event->allDay ? '' : ', '.$event->start->format('H:i');

        return new self(
            kind: self::EVENT,
            title: $event->title,
            detail: match (true) {
                $event->isOngoing => $event->ongoingDetail(),
                $event->isToday => 'Hoy'.$time,
                default => Str::ucfirst($event->dateAndTime()),
            },
            href: $event->href(),
        );
    }
}
