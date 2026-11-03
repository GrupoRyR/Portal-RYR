<?php

namespace App\View\Home;

use App\Models\WorkItem;
use Carbon\CarbonImmutable;

/**
 * One line of «Tareas pendientes» (App\View\Home\WorkList): title, the
 * catalog app it comes from, when it is due («Vencida», «Vence hoy, 17:00»,
 * «Vence el 6 nov»), «Ver tarea» or «Ver aprobación» (the app's `url`, in a
 * new tab, §5) and, only when WorkItem::canBeCompletedFromPortal(), the
 * action of «Completar» (§6). Times in Bogotá.
 */
final readonly class WorkRow
{
    public function __construct(
        public int $id,
        public bool $isApproval,
        public string $title,
        public ?string $appName,
        public ?string $url,
        public ?string $dueIso,
        public ?string $dueLabel,
        public bool $overdue,
        public ?string $completeAction,
    ) {}

    public static function from(WorkItem $item, CarbonImmutable $now): self
    {
        $timezone = (string) config('app.timezone');
        $now = $now->setTimezone($timezone);
        $due = $item->due_at === null ? null : CarbonImmutable::instance($item->due_at)->setTimezone($timezone);
        $overdue = $due !== null && $due->lessThan($now);

        $dueLabel = match (true) {
            $due === null => null,
            $overdue => 'Vencida',
            $due->isSameDay($now) => 'Vence hoy, '.$due->format('H:i'),
            default => 'Vence el '.str_replace('.', '', $due->locale('es')->isoFormat('D MMM')).($due->year === $now->year ? '' : " {$due->year}"),
        };

        return new self(
            id: (int) $item->id,
            isApproval: $item->kind === WorkItem::KIND_APPROVAL,
            title: (string) $item->title,
            appName: $item->integration?->app?->name,
            url: $item->url === null || $item->url === '' ? null : (string) $item->url,
            dueIso: $due?->toIso8601String(),
            dueLabel: $dueLabel,
            overdue: $overdue,
            completeAction: $item->canBeCompletedFromPortal() ? route('work-items.complete', $item) : null,
        );
    }

    /** «Ver tarea» or «Ver aprobación». */
    public function viewLabel(): string
    {
        return $this->isApproval ? 'Ver aprobación' : 'Ver tarea';
    }
}
