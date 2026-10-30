<?php

namespace App\Announcements;

use App\Branding\MailBrand;
use App\Microsoft\GraphAppException;
use App\Microsoft\TenantMailSender;
use App\Models\Announcement;
use App\Models\AnnouncementListEmail;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Announcements emailed to each company's all-staff list
 * (odd/tasks/inicio-fase-2.md WU4, decision of 2026-10-30).
 *
 * An announcement that asks for it (`email_lists`) goes, once it is
 * published, to the list of each company it addresses that is active and
 * has both addresses (Admin › Empresas): one email per company, from the
 * company's own mailbox with a token of the company's own tenant (an
 * Exchange list only accepts internal senders), always with Microsoft
 * Graph (App\Microsoft\TenantMailSender), never SMTP or the portal's
 * notice mailer. The cron (portal:send-announcement-list-emails, every
 * minute) sends a bounded batch ({@see send()}); no queue.
 *
 * Each email is recorded (App\Models\AnnouncementListEmail) before Graph is
 * called, so it goes out once: editing the announcement afterwards does
 * not send it again, and a run that dies halfway leaves its row
 * «Interrupted» instead of sending twice. Only «Reintentar» ({@see retry()})
 * sends a failed one again.
 */
class AnnouncementListEmails
{
    /** Emails per run of the cron. */
    public const BATCH = 10;

    /**
     * A row still «sending» after this many minutes belongs to a run that
     * died (a run sends at most BATCH emails of a few seconds each).
     */
    public const STALE_MINUTES = 15;

    /** Named audit entry of «Reintentar». */
    public const RETRY_EVENT = 'announcement.list_email_retried';

    public function __construct(private readonly TenantMailSender $sender) {}

    /**
     * The companies whose lists an announcement with this audience would
     * reach now, by name: active, with both addresses at their own
     * domains. For the editor's checkbox.
     *
     * @param  array<int|string>  $companyIds  Ignored when the audience is every company.
     * @return Collection<int, Company>
     */
    public function recipients(string $audience, array $companyIds): Collection
    {
        return Company::query()
            ->withListEmail()
            ->when($audience !== Announcement::AUDIENCE_ALL, fn (Builder $query) => $query->whereKey(array_map('intval', $companyIds)))
            ->get()
            ->filter(fn (Company $company) => $company->canReceiveListEmail())
            ->sortBy(fn (Company $company) => mb_strtolower(self::label($company)))
            ->values();
    }

    /**
     * «Derivados (todos@derivadoscol.com) y Segurex (todos@segurex.com)».
     *
     * @param  Collection<int, Company>  $companies
     */
    public static function sentence(Collection $companies): string
    {
        $names = $companies->map(fn (Company $company) => self::label($company).' ('.$company->all_staff_email.')')->values();

        return $names->count() > 1 ? $names->slice(0, -1)->implode(', ').' y '.$names->last() : (string) $names->first();
    }

    /**
     * How the email to each list went, for the editor: the companies the
     * announcement addresses now that have a list, or a row from before.
     * A company it no longer addresses is left out, so an editor of one
     * company never sees another's list.
     *
     * @return list<array{row_id: int|null, company: string, list: string|null, state: string, sent_at: CarbonInterface|null, error: string|null, next_retry_at: CarbonInterface|null}>
     */
    public function report(Announcement $announcement): array
    {
        $rows = $announcement->listEmails()->get()->keyBy('company_id');

        if (! $announcement->email_lists && $rows->isEmpty()) {
            return [];
        }

        $companies = $announcement->addressedCompanies()
            ->where(fn (Builder $query) => $query
                ->whereIn('companies.id', $rows->keys()->all())
                ->when($announcement->email_lists, fn (Builder $query) => $query->orWhere(fn (Builder $query) => $query->withListEmail())))
            ->get()
            ->sortBy(fn (Company $company) => mb_strtolower(self::label($company)));

        $status = $announcement->status;

        return $companies->map(function (Company $company) use ($rows, $announcement, $status) {
            /** @var AnnouncementListEmail|null $row */
            $row = $rows->get($company->id);
            $waiting = $row === null || $row->status === AnnouncementListEmail::STATUS_PENDING;

            return [
                'row_id' => $row?->id,
                'company' => self::label($company),
                'list' => $company->all_staff_email,
                'state' => match (true) {
                    ! $waiting => $row->status,
                    ! $announcement->email_lists => 'off',
                    $status === Announcement::STATUS_SCHEDULED => 'scheduled',
                    $status === Announcement::STATUS_PUBLISHED => AnnouncementListEmail::STATUS_PENDING,
                    default => 'not_published',
                },
                'sent_at' => $row?->sent_at,
                'error' => $row?->last_error,
                'next_retry_at' => $row?->nextRetryAt(),
            ];
        })->values()->all();
    }

    /**
     * «Reintentar»: sets a failed email of the announcement back to
     * pending for the next run of the cron, at most once every
     * AnnouncementListEmail::RETRY_MINUTES per row, and audits it. Only
     * for a company the announcement addresses now. Whether the user may
     * edit the announcement is the caller's job.
     *
     * @throws ListEmailRetryRefused
     */
    public function retry(Announcement $announcement, int $rowId, User $by): AnnouncementListEmail
    {
        $row = $announcement->listEmails()->with('company')->whereKey($rowId)->first();

        if ($row === null
            || $row->status !== AnnouncementListEmail::STATUS_FAILED
            || ! $announcement->addressedCompanies()->whereKey($row->company_id)->exists()) {
            throw ListEmailRetryRefused::notRetryable();
        }

        if (($next = $row->nextRetryAt()) !== null) {
            throw ListEmailRetryRefused::tooSoon($next);
        }

        // Conditional, so two clicks at once set it back only once.
        $updated = AnnouncementListEmail::query()
            ->whereKey($row->id)
            ->where('status', AnnouncementListEmail::STATUS_FAILED)
            ->where(fn (Builder $query) => $query
                ->whereNull('retried_at')
                ->orWhere('retried_at', '<=', now()->subMinutes(AnnouncementListEmail::RETRY_MINUTES)))
            ->update(['status' => AnnouncementListEmail::STATUS_PENDING, 'retried_at' => now()]);

        if ($updated !== 1) {
            throw ListEmailRetryRefused::notRetryable();
        }

        activity(Announcement::AUDIT_LOG)
            ->performedOn($announcement)
            ->causedBy($by)
            ->event(self::RETRY_EVENT)
            ->withProperties(['attributes' => [
                'company' => $row->company === null ? null : self::label($row->company),
                'previous_error' => $row->last_error,
            ]])
            ->log(self::RETRY_EVENT);

        return $row->refresh();
    }

    /**
     * Sends up to $limit emails, oldest announcement first. A failure is
     * recorded and logged and never stops the batch.
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

        $announcements = Announcement::query()->whereKey($due->pluck('announcement_id')->unique()->all())->get()->keyBy('id');
        $companies = Company::query()->whereKey($due->pluck('company_id')->unique()->all())->get()->keyBy('id');

        foreach ($due as $pair) {
            $announcement = $announcements->get($pair->announcement_id);
            $company = $companies->get($pair->company_id);

            if ($announcement === null || $company === null) {
                continue;
            }

            $row = $this->claim($announcement, $company, $pair->row_id === null ? null : (int) $pair->row_id);

            if ($row !== null) {
                $result[$this->deliver($row, $announcement, $company) ? 'sent' : 'failed']++;
            }
        }

        return $result;
    }

    /**
     * The HTML of the email to the company's list: its brand, the title,
     * the text escaped with its line breaks, the image when it has an
     * https address (never an attachment) and «Ver en el portal».
     */
    public function html(Announcement $announcement, Company $company): string
    {
        // The cron has no request: the link is built from APP_URL.
        $url = rtrim((string) config('app.url'), '/').route('announcements.show', $announcement, false);
        $image = $announcement->imageUrl();
        $body = str_replace(["\r\n", "\r", "\n"], '<br>', e($announcement->body));

        return (string) app(Markdown::class)->render('mail.announcement-list', [
            'brand' => MailBrand::for($company),
            'title' => $announcement->title,
            'body' => new HtmlString($body),
            'imageUrl' => is_string($image) && str_starts_with($image, 'https://') ? $image : null,
            'url' => $url,
            'list' => $company->all_staff_email,
        ]);
    }

    /**
     * «10:01», in the portal's time zone.
     */
    public static function time(CarbonInterface $moment): string
    {
        return $moment->copy()->timezone(config('app.timezone'))->format('H:i');
    }

    /**
     * What the editor can do about a failed email, by its code; null when
     * there is nothing specific to say.
     */
    public static function hint(?string $error): ?string
    {
        return match (true) {
            $error === null => null,
            $error === 'ErrorAccessDenied' => 'El buzón remitente no tiene permiso de envío. Revisa los pasos de Exchange en docs/despliegue.md.',
            in_array($error, ['MailboxNotEnabledForRESTAPI', 'ErrorInvalidUser', 'ResourceNotFound', 'HTTP 404'], true) => 'El buzón remitente no existe o no es un buzón de Exchange Online. Revisa la dirección en Admin › Empresas.',
            str_starts_with($error, 'AADSTS') => 'Microsoft no le dio a la app un permiso en el inquilino de esta empresa (consentimiento o secreto). Revisa docs/despliegue.md.',
            $error === AnnouncementListEmail::ERROR_INTERRUPTED => 'El envío se interrumpió y no se sabe si salió. Revisa la lista antes de reintentar.',
            $error === AnnouncementListEmail::ERROR_DOMAIN_MISMATCH => 'El buzón o la lista no son de un dominio de la empresa. Corrígelos en Admin › Empresas.',
            default => null,
        };
    }

    public static function label(Company $company): string
    {
        return $company->short_name ?: $company->name;
    }

    /**
     * The pairs to send now: a published announcement that asks for it,
     * an active company it addresses with both addresses, and no email
     * yet (or one set back to pending by «Reintentar»). One query.
     *
     * @return Collection<int, object{announcement_id: int, company_id: int, row_id: int|null}>
     */
    private function due(int $limit): Collection
    {
        return Announcement::query()
            ->inStatus(Announcement::STATUS_PUBLISHED)
            ->where('announcements.email_lists', true)
            ->join('companies', fn (JoinClause $join) => $join
                ->where('companies.is_active', true)
                ->whereNotNull('companies.all_staff_email')
                ->whereNotNull('companies.mail_sender'))
            ->where(fn (Builder $query) => $query
                ->where('announcements.audience', Announcement::AUDIENCE_ALL)
                ->orWhereExists(fn ($query) => $query
                    ->from('announcement_company')
                    ->whereColumn('announcement_company.announcement_id', 'announcements.id')
                    ->whereColumn('announcement_company.company_id', 'companies.id')))
            ->leftJoin('announcement_list_emails as emails', fn (JoinClause $join) => $join
                ->on('emails.announcement_id', '=', 'announcements.id')
                ->on('emails.company_id', '=', 'companies.id'))
            ->where(fn (Builder $query) => $query
                ->whereNull('emails.id')
                ->orWhere('emails.status', AnnouncementListEmail::STATUS_PENDING))
            ->orderBy('announcements.starts_at')
            ->orderBy('announcements.id')
            ->orderBy('companies.id')
            ->limit($limit)
            ->toBase()
            ->get(['announcements.id as announcement_id', 'companies.id as company_id', 'emails.id as row_id']);
    }

    /**
     * Records the email as «sending» before Graph is called. Null when
     * another run got there first: the unique pair, or the pending row
     * already claimed.
     */
    private function claim(Announcement $announcement, Company $company, ?int $rowId): ?AnnouncementListEmail
    {
        if ($rowId === null) {
            try {
                return AnnouncementListEmail::query()->create([
                    'announcement_id' => $announcement->id,
                    'company_id' => $company->id,
                    'status' => AnnouncementListEmail::STATUS_SENDING,
                    'attempts' => 1,
                ]);
            } catch (UniqueConstraintViolationException) {
                return null;
            }
        }

        $claimed = AnnouncementListEmail::query()
            ->whereKey($rowId)
            ->where('status', AnnouncementListEmail::STATUS_PENDING)
            ->update(['status' => AnnouncementListEmail::STATUS_SENDING, 'attempts' => DB::raw('attempts + 1')]);

        return $claimed === 1 ? AnnouncementListEmail::query()->find($rowId) : null;
    }

    private function deliver(AnnouncementListEmail $row, Announcement $announcement, Company $company): bool
    {
        $exception = null;

        try {
            if (! $company->canReceiveListEmail()) {
                $error = AnnouncementListEmail::ERROR_DOMAIN_MISMATCH;
            } else {
                $this->sender->send(
                    (string) $company->tenant_id,
                    (string) $company->mail_sender,
                    (string) $company->all_staff_email,
                    $announcement->title,
                    $this->html($announcement, $company),
                );
                $error = null;
            }
        } catch (Throwable $e) {
            $exception = $e;
            $error = self::errorCode($e);
        }

        if ($error !== null) {
            Log::warning('Announcement list email could not be sent.', [
                'announcement_id' => $announcement->id,
                'company_id' => $company->id,
                'error' => $error,
                'exception' => $exception === null ? null : $exception::class,
            ]);
        }

        $row->forceFill([
            'status' => $error === null ? AnnouncementListEmail::STATUS_SENT : AnnouncementListEmail::STATUS_FAILED,
            'last_error' => $error,
            'sent_at' => $error === null ? now() : null,
        ])->save();

        return $error === null;
    }

    /**
     * A short code for the row and the log: Graph's error code, the first
     * AADSTS code of a refused token, the HTTP status, or «Unavailable»
     * (Microsoft not reached, or the app's id or secret missing).
     */
    private static function errorCode(Throwable $e): string
    {
        if (! $e instanceof GraphAppException) {
            return 'Unexpected';
        }

        return match (true) {
            $e->graphCode !== null => $e->graphCode,
            $e->aadsts !== [] => 'AADSTS'.$e->aadsts[0],
            $e->status !== null => 'HTTP '.$e->status,
            default => 'Unavailable',
        };
    }

    /**
     * Rows a dead run left «sending»: it is not known whether they left,
     * so they fail as «Interrupted» and wait for a person to retry them.
     */
    private function markInterrupted(): void
    {
        AnnouncementListEmail::query()
            ->where('status', AnnouncementListEmail::STATUS_SENDING)
            ->where('updated_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->update(['status' => AnnouncementListEmail::STATUS_FAILED, 'last_error' => AnnouncementListEmail::ERROR_INTERRUPTED]);
    }
}
