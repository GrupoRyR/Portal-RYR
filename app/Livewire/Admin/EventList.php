<?php

namespace App\Livewire\Admin;

use App\Models\Company;
use App\Models\Event;
use App\Models\User;
use App\View\Home\EventDate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin «Eventos»: status tabs with counts and the list, newest start
 * first. Announcements editors only ever see the events of their own
 * company (Event::scopeManageableBy). Authorized on mount and on every
 * render, so a role taken away stops the next click.
 */
class EventList extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public const PER_PAGE = 20;

    /** Tab labels, in the order of Event::STATUSES. */
    public const TAB_LABELS = [
        Event::STATUS_PUBLISHED => 'Publicados',
        Event::STATUS_SCHEDULED => 'Programados',
        Event::STATUS_PAST => 'Pasados',
        Event::STATUS_WITHDRAWN => 'Retirados',
    ];

    /** Status chip labels (x-admin.event-status). */
    public const STATUS_LABELS = [
        Event::STATUS_PUBLISHED => 'Publicado',
        Event::STATUS_SCHEDULED => 'Programado',
        Event::STATUS_PAST => 'Pasado',
        Event::STATUS_WITHDRAWN => 'Retirado',
    ];

    #[Url(as: 'estado')]
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Event::class);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $this->authorize('viewAny', Event::class);

        /** @var User $user */
        $user = Auth::user();
        $status = in_array($this->status, Event::STATUSES, true) ? $this->status : null;
        $base = fn () => Event::query()->manageableBy($user);

        $counts = ['' => $base()->count()];
        foreach (Event::STATUSES as $value) {
            $counts[$value] = $base()->inStatus($value)->count();
        }

        $events = $base()
            ->when($status, fn (Builder $query, string $status) => $query->inStatus($status))
            ->with('companies:id,name,short_name')
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();

        return view('livewire.admin.event-list', [
            'events' => $events,
            'rows' => $events->getCollection()->map(fn (Event $event) => [
                'event' => $event,
                'status' => $event->status,
                'details' => EventDate::from($event, $today)->when().($event->repeatsYearly() ? ' · Cada año' : '').' · '.($event->isForAllCompanies()
                    ? 'Todas las empresas'
                    : $event->companies->map(fn (Company $company) => $company->short_name ?: $company->name)->sort()->implode(', ')),
            ]),
            'counts' => $counts,
            'current' => $status ?? '',
        ]);
    }
}
