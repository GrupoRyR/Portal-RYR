<?php

namespace App\Livewire\Admin;

use App\Models\Company;
use App\Models\Event;
use App\Models\User;
use App\Policies\AnnouncementPolicy;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Creates or edits one company event (odd/tasks/inicio-fase-2.md WU2):
 * what, when (timed or all day), where, for whom and from when it shows.
 * The same people as announcements (App\Policies\EventPolicy): editors only
 * get their own company as audience, and `publishTo` is checked on every
 * save, whatever the form sent. Every action authorizes again.
 *
 * Dates are typed in the app timezone (Bogotá): `datetime-local` for a
 * timed event, `date` for an all-day one, whose «Termina» is its last day
 * (inclusive; the same day as the start means one day).
 *
 * «Se repite cada año» (odd/tasks/ajustes-inicio-y-tareas.md T3) is only
 * for an all-day event: unticking «Todo el día» clears it, and a timed
 * event sent with it is refused.
 */
class EventEditor extends Component
{
    use AuthorizesRequests;

    public const DATETIME_FORMAT = 'Y-m-d\TH:i';

    public const DATE_FORMAT = 'Y-m-d';

    /** Start time given to an all-day event that becomes a timed one. */
    private const DEFAULT_TIME = '09:00';

    #[Locked]
    public ?Event $event = null;

    public string $title = '';

    public string $description = '';

    public string $location = '';

    public bool $allDay = false;

    public string $startsAt = '';

    public string $endsAt = '';

    /** «Se repite cada año»: the dates are the first occurrence. */
    public bool $repeatsYearly = false;

    public string $audience = Event::AUDIENCE_ALL;

    /** @var list<int|string> */
    public array $companyIds = [];

    /** «Se muestra desde»: before it, the event is scheduled. */
    public string $publishedAt = '';

    public function mount(?Event $event = null): void
    {
        if ($event === null || ! $event->exists) {
            $this->authorize('create', Event::class);
            $this->publishedAt = Carbon::now()->format(self::DATETIME_FORMAT);

            if ($this->restricted()) {
                $this->audience = Event::AUDIENCE_COMPANIES;
                $this->companyIds = [(string) $this->user()->company_id];
            }

            return;
        }

        $this->authorize('update', $event);

        $format = $event->all_day ? self::DATE_FORMAT : self::DATETIME_FORMAT;

        $this->event = $event;
        $this->title = $event->title;
        $this->description = (string) $event->description;
        $this->location = (string) $event->location;
        $this->allDay = $event->all_day;
        $this->repeatsYearly = $event->repeatsYearly();
        $this->startsAt = $event->starts_at->format($format);
        $this->endsAt = (string) $event->ends_at?->format($format);
        $this->audience = $event->audience;
        $this->companyIds = $event->companies()->orderBy('companies.id')->pluck('companies.id')->map(fn ($id) => (string) $id)->all();
        $this->publishedAt = $event->published_at->format(self::DATETIME_FORMAT);
    }

    /**
     * Switching «Todo el día» keeps the days: an all-day event keeps the
     * dates (an end on the same day goes away), a timed one starts at
     * DEFAULT_TIME and has no end until one is chosen.
     */
    public function updatedAllDay(): void
    {
        $start = substr($this->startsAt, 0, 10);
        $end = substr($this->endsAt, 0, 10);

        if ($this->allDay) {
            $this->startsAt = $start;
            $this->endsAt = $end === $start ? '' : $end;
        } else {
            $this->startsAt = $start === '' ? '' : $start.'T'.self::DEFAULT_TIME;
            $this->endsAt = '';
            $this->repeatsYearly = false;
        }

        $this->resetValidation(['startsAt', 'endsAt', 'repeatsYearly']);
    }

    public function save(): mixed
    {
        $this->event === null
            ? $this->authorize('create', Event::class)
            : $this->authorize('update', $this->event);

        $this->title = trim($this->title);
        $this->description = trim($this->description);
        $this->location = trim($this->location);

        $this->validate();

        $companyIds = $this->audience === Event::AUDIENCE_COMPANIES ? $this->companyIds : [];
        $this->authorize('publishTo', [Event::class, $this->audience, $companyIds]);

        $startsAt = $this->parse($this->startsAt);
        $endsAt = $this->endsAt === '' ? null : $this->parse($this->endsAt);
        if ($this->allDay && $endsAt?->equalTo($startsAt)) {
            $endsAt = null;
        }

        $creating = $this->event === null;
        $attributes = [
            'title' => $this->title,
            'description' => $this->description === '' ? null : $this->description,
            'location' => $this->location === '' ? null : $this->location,
            'all_day' => $this->allDay,
            'repeats_yearly' => $this->allDay && $this->repeatsYearly,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'audience' => $this->audience,
            'published_at' => Carbon::createFromFormat(self::DATETIME_FORMAT.'|', $this->publishedAt),
        ];

        if ($creating) {
            $attributes['created_by'] = $this->user()->id;
        }

        $event = ($this->event ?? new Event)->saveWithCompanies($attributes, $companyIds);

        session()->flash('status', $creating ? 'Evento creado.' : 'Cambios guardados.');

        return $this->redirectRoute('admin.events.edit', $event);
    }

    /**
     * «Retirar»: off the home screen and /eventos at once (the button asks first).
     */
    public function withdraw(): void
    {
        $this->authorize('update', $this->event);

        $this->event->withdraw();
        session()->flash('status', 'Evento retirado. Ya no se muestra en el portal.');
    }

    public function republish(): void
    {
        $this->authorize('update', $this->event);

        $this->event->republish();
        session()->flash('status', 'Evento publicado de nuevo.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $format = $this->allDay ? self::DATE_FORMAT : self::DATETIME_FORMAT;
        $specific = $this->audience === Event::AUDIENCE_COMPANIES;

        return [
            'title' => ['required', 'string', 'max:'.Event::TITLE_MAX],
            'description' => ['nullable', 'string', 'max:'.Event::DESCRIPTION_MAX],
            'location' => ['nullable', 'string', 'max:'.Event::LOCATION_MAX],
            'allDay' => ['boolean'],
            'repeatsYearly' => $this->allDay ? ['boolean'] : ['boolean', 'declined'],
            'startsAt' => ['required', 'date_format:'.$format],
            'endsAt' => ['nullable', 'date_format:'.$format, $this->allDay ? 'after_or_equal:startsAt' : 'after:startsAt'],
            'audience' => ['required', Rule::in(Event::AUDIENCES)],
            'companyIds' => $specific ? ['required', 'array'] : ['array'],
            'companyIds.*' => ['distinct', Rule::exists('companies', 'id')],
            'publishedAt' => ['required', 'date_format:'.self::DATETIME_FORMAT],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'required' => 'Este campo es obligatorio.',
            'title.required' => 'Escribe el título.',
            'title.max' => 'Usa como máximo '.Event::TITLE_MAX.' caracteres.',
            'description.max' => 'Usa como máximo '.Event::DESCRIPTION_MAX.' caracteres.',
            'location.max' => 'Usa como máximo '.Event::LOCATION_MAX.' caracteres.',
            'startsAt.required' => 'Elige cuándo empieza.',
            'startsAt.date_format' => $this->allDay ? 'Elige una fecha válida.' : 'Elige una fecha y hora válidas.',
            'endsAt.date_format' => $this->allDay ? 'Elige una fecha válida.' : 'Elige una fecha y hora válidas.',
            'endsAt.after' => '«Termina» debe ser posterior a «Empieza».',
            'endsAt.after_or_equal' => 'El último día no puede ser anterior al primero.',
            'repeatsYearly.declined' => 'Solo un evento de todo el día puede repetirse cada año.',
            'audience.in' => 'Elige para quién es el evento.',
            'companyIds.required' => 'Elige al menos una empresa.',
            'companyIds.*.exists' => 'Elige empresas de la lista.',
            'publishedAt.required' => 'Elige desde cuándo se muestra.',
            'publishedAt.date_format' => 'Elige una fecha y hora válidas.',
        ];
    }

    public function render(): View
    {
        $restricted = $this->restricted();

        return view('livewire.admin.event-editor', [
            'restricted' => $restricted,
            'companies' => $restricted
                ? Company::query()->whereKey($this->user()->company_id)->get(['id', 'name', 'short_name'])
                : Company::query()->orderBy('name')->get(['id', 'name', 'short_name', 'is_active']),
            'status' => $this->event?->status,
        ]);
    }

    /**
     * Announcements editors (not admins) are limited to their company.
     */
    private function restricted(): bool
    {
        return ! AnnouncementPolicy::manages($this->user());
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }

    /**
     * A validated form value in the app timezone (Bogotá): midnight for a
     * date, seconds at zero for a date and time.
     */
    private function parse(string $value): Carbon
    {
        return Carbon::createFromFormat(($this->allDay ? self::DATE_FORMAT : self::DATETIME_FORMAT).'|', $value)
            ?: throw new \InvalidArgumentException("Invalid date [{$value}].");
    }
}
