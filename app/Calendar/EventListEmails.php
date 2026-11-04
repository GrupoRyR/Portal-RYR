<?php

namespace App\Calendar;

use App\Announcements\AnnouncementListEmails;
use App\Announcements\ListEmailRetryRefused;
use App\Branding\MailBrand;
use App\Microsoft\TenantMailSender;
use App\Models\Company;
use App\Models\Event;
use App\Models\EventListEmail;
use App\Models\User;
use App\View\Home\EventDate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Events emailed to each company's all-staff list (odd/tasks/observaciones.md
 * O1), with the rules of announcements (App\Announcements\AnnouncementListEmails):
 *
 * - saving an event with the box ticked (`email_lists`, on by default)
 *   records one pending email for each company it addresses that can get
 *   it at that moment and has none yet ({@see queue()});
 * - the cron (portal:send-announcement-list-emails, every minute) sends a
 *   bounded batch of them once the event is published and not over
 *   ({@see send()}), from the company's own mailbox with a token of the
 *   company's own tenant, always with Microsoft Graph; no queue worker;
 * - the row turns «sending» before Graph is called, so a run that dies
 *   halfway leaves it «Interrupted» instead of sending twice, and only
 *   «Reintentar» ({@see retry()}) sends a failed one again.
 *
 * The helpers that do not depend on what is sent (the company label, the
 * error codes and their hints) are the announcements' ones.
 */
class EventListEmails
{
    /** Emails per run of the cron. */
    public const BATCH = AnnouncementListEmails::BATCH;

    public const STALE_MINUTES = AnnouncementListEmails::STALE_MINUTES;

    /** Named audit entry of «Reintentar». */
    public const RETRY_EVENT = 'event.list_email_retried';

    public function __construct(private readonly TenantMailSender $sender) {}

    /**
     * A pending email for each company the event addresses that can get it
     * now and has no email of it yet. Returns how many were recorded. The
     * caller has already authorized the save.
     */
    public function queue(Event $event): int
    {
        if (! $event->email_lists) {
            return 0;
        }

        $now = now();
        $rows = $event->addressedCompanies()
            ->withListEmail()
            ->whereNotIn('companies.id', $event->listEmails()->select('company_id'))
            ->get()
            ->filter(fn (Company $company) => $company->canReceiveListEmail())
            ->map(fn (Company $company) => [
                'event_id' => $event->id,
                'company_id' => $company->id,
                'status' => EventListEmail::STATUS_PENDING,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        // A save at the same moment may have recorded one already: the unique pair wins.
        return $rows === [] ? 0 : EventListEmail::query()->insertOrIgnore($rows);
    }

    /**
     * How the email to each list went, for the editor; the same states as
     * AnnouncementListEmails::report() («past» stands for «not_published»).
     *
     * @return list<array{row_id: int|null, company: string, list: string|null, state: string, sent_at: CarbonInterface|null, error: string|null, next_retry_at: CarbonInterface|null}>
     */
    public function report(Event $event): array
    {
        $rows = $event->listEmails()->get()->keyBy('company_id');

        if (! $event->email_lists && $rows->isEmpty()) {
            return [];
        }

        $companies = $event->addressedCompanies()
            ->where(fn (Builder $query) => $query
                ->whereIn('companies.id', $rows->keys()->all())
                ->when($event->email_lists, fn (Builder $query) => $query->orWhere(fn (Builder $query) => $query->withListEmail())))
            ->get()
            ->filter(fn (Company $company) => $rows->has($company->id) || $company->canReceiveListEmail())
            ->sortBy(fn (Company $company) => mb_strtolower(AnnouncementListEmails::label($company)));

        $status = $event->status;

        return $companies->map(function (Company $company) use ($rows, $event, $status) {
            /** @var EventListEmail|null $row */
            $row = $rows->get($company->id);

            return [
                'row_id' => $row?->id,
                'company' => AnnouncementListEmails::label($company),
                'list' => $company->all_staff_email,
                'state' => match (true) {
                    $row === null => 'not_queued',
                    $row->status !== EventListEmail::STATUS_PENDING => $row->status,
                    ! $event->email_lists => 'off',
                    ! $company->canReceiveListEmail() => 'company_unavailable',
                    $status === Event::STATUS_SCHEDULED => 'scheduled',
                    $status === Event::STATUS_PUBLISHED => EventListEmail::STATUS_PENDING,
                    default => 'not_published',
                },
                'sent_at' => $row?->sent_at,
                'error' => $row?->last_error,
                'next_retry_at' => $row?->nextRetryAt(),
            ];
        })->values()->all();
    }

    /**
     * «Reintentar»: sets a failed email of the event back to pending for
     * the next run of the cron, at most once every
     * EventListEmail::RETRY_MINUTES per row, and audits it. Only for a
     * company the event addresses now. Whether the user may edit the event
     * is the caller's job.
     *
     * @throws ListEmailRetryRefused
     */
    public function retry(Event $event, int $rowId, User $by): EventListEmail
    {
        $row = $event->listEmails()->with('company')->whereKey($rowId)->first();

        if ($row === null
            || $row->status !== EventListEmail::STATUS_FAILED
            || ! $event->addressedCompanies()->whereKey($row->company_id)->exists()) {
            throw ListEmailRetryRefused::notRetryable();
        }

        if (($next = $row->nextRetryAt()) !== null) {
            throw ListEmailRetryRefused::tooSoon($next);
        }

        // Conditional, so two clicks at once set it back only once.
        $updated = EventListEmail::query()
            ->whereKey($row->id)
            ->where('status', EventListEmail::STATUS_FAILED)
            ->where(fn (Builder $query) => $query
                ->whereNull('retried_at')
                ->orWhere('retried_at', '<=', now()->subMinutes(EventListEmail::RETRY_MINUTES)))
            ->update(['status' => EventListEmail::STATUS_PENDING, 'retried_at' => now()]);

        if ($updated !== 1) {
            throw ListEmailRetryRefused::notRetryable();
        }

        activity(Event::AUDIT_LOG)
            ->performedOn($event)
            ->causedBy($by)
            ->event(self::RETRY_EVENT)
            ->withProperties(['attributes' => [
                'company' => $row->company === null ? null : AnnouncementListEmails::label($row->company),
                'previous_error' => $row->last_error,
            ]])
            ->log(self::RETRY_EVENT);

        return $row->refresh();
    }

    /**
     * Sends up to $limit emails, soonest event first. A failure is recorded
     * and logged and never stops the batch.
     *
     * @return array{sent: int, failed: int}
     */
    public function send(int $limit = self::BATCH): array
    {
        $this->markInterrupted();

        $result = ['sent' => 0, 'failed' => 0];
        $due = $this->due($limit);

        if ($due->isEmpty()) {
            return $result;
        }

        $events = Event::query()->whereKey($due->pluck('event_id')->unique()->all())->get()->keyBy('id');
        $companies = Company::query()->whereKey($due->pluck('company_id')->unique()->all())->get()->keyBy('id');

        foreach ($due as $pair) {
            $event = $events->get($pair->event_id);
            $company = $companies->get($pair->company_id);

            if ($event === null || $company === null) {
                continue;
            }

            $row = $this->claim((int) $pair->row_id);

            if ($row !== null) {
                $result[$this->deliver($row, $event, $company) ? 'sent' : 'failed']++;
            }
        }

        return $result;
    }

    /**
     * The HTML of the email to the company's list: its brand, the title,
     * when and where, the description escaped with its line breaks and
     * «Ver en el portal». Every text of a person stays on one line inside
     * an HTML block (see AnnouncementListEmails::html()), so none of it is
     * read as Markdown.
     */
    public function html(Event $event, Company $company): string
    {
        // The cron has no request: the link is built from APP_URL.
        $url = rtrim((string) config('app.url'), '/').route('events.index', [], false);
        $brand = MailBrand::for($company);
        $description = $event->description === null || trim($event->description) === ''
            ? null
            : new HtmlString(str_replace(["\r\n", "\r", "\n"], '<br>', e($event->description)));

        return (string) app(Markdown::class)->render('mail.event-list', [
            'brand' => $brand,
            'companyName' => AnnouncementListEmails::oneLine($brand->name),
            'title' => AnnouncementListEmails::oneLine($event->title),
            'when' => AnnouncementListEmails::oneLine(EventDate::from($event, CarbonImmutable::now())->when()),
            'location' => $event->location === null ? null : AnnouncementListEmails::oneLine($event->location),
            'description' => $description,
            'url' => $url,
            'list' => $company->all_staff_email,
        ]);
    }

    /**
     * The pending emails to send now, soonest event first: the event is
     * published (and not over) and still asks for it, the company is
     * active, has both addresses and is still addressed. One query.
     *
     * @return Collection<int, object{event_id: int, company_id: int, row_id: int}>
     */
    private function due(int $limit): Collection
    {
        return Event::query()
            ->inStatus(Event::STATUS_PUBLISHED)
            ->where('events.email_lists', true)
            ->join('event_list_emails as emails', 'emails.event_id', '=', 'events.id')
            ->where('emails.status', EventListEmail::STATUS_PENDING)
            ->join('companies', 'companies.id', '=', 'emails.company_id')
            ->where('companies.is_active', true)
            ->whereNotNull('companies.all_staff_email')
            ->whereNotNull('companies.mail_sender')
            ->where(fn (Builder $query) => $query
                ->where('events.audience', Event::AUDIENCE_ALL)
                ->orWhereExists(fn ($query) => $query
                    ->from('event_company')
                    ->whereColumn('event_company.event_id', 'events.id')
                    ->whereColumn('event_company.company_id', 'companies.id')))
            ->orderBy('events.published_at')
            ->orderBy('events.id')
            ->orderBy('companies.id')
            ->limit($limit)
            ->toBase()
            ->get(['events.id as event_id', 'companies.id as company_id', 'emails.id as row_id']);
    }

    /**
     * Turns a pending email «sending» before Graph is called. Null when
     * another run claimed it first.
     */
    private function claim(int $rowId): ?EventListEmail
    {
        $claimed = EventListEmail::query()
            ->whereKey($rowId)
            ->where('status', EventListEmail::STATUS_PENDING)
            ->update(['status' => EventListEmail::STATUS_SENDING, 'attempts' => DB::raw('attempts + 1')]);

        return $claimed === 1 ? EventListEmail::query()->find($rowId) : null;
    }

    private function deliver(EventListEmail $row, Event $event, Company $company): bool
    {
        $exception = null;

        try {
            if (! $company->canReceiveListEmail()) {
                $error = EventListEmail::ERROR_DOMAIN_MISMATCH;
            } else {
                $this->sender->send(
                    (string) $company->tenant_id,
                    (string) $company->mail_sender,
                    (string) $company->all_staff_email,
                    AnnouncementListEmails::oneLine($event->title),
                    $this->html($event, $company),
                );
                $error = null;
            }
        } catch (Throwable $e) {
            $exception = $e;
            $error = AnnouncementListEmails::errorCode($e);
        }

        if ($error !== null) {
            Log::warning('Event list email could not be sent.', [
                'event_id' => $event->id,
                'company_id' => $company->id,
                'error' => $error,
                'exception' => $exception === null ? null : $exception::class,
            ]);
        }

        $row->forceFill([
            'status' => $error === null ? EventListEmail::STATUS_SENT : EventListEmail::STATUS_FAILED,
            'last_error' => $error,
            'sent_at' => $error === null ? now() : null,
        ])->save();

        return $error === null;
    }

    /**
     * Rows a dead run left «sending»: they fail as «Interrupted» and wait
     * for a person to retry them.
     */
    private function markInterrupted(): void
    {
        EventListEmail::query()
            ->where('status', EventListEmail::STATUS_SENDING)
            ->where('updated_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->update(['status' => EventListEmail::STATUS_FAILED, 'last_error' => EventListEmail::ERROR_INTERRUPTED]);
    }
}
