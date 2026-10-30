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
 * Sending is explicit: saving an announcement with the box ticked
 * (`email_lists`) records one pending email for each company it addresses
 * that can get it at that moment (active, both addresses at its own
 * domains, Admin › Empresas) and has none yet ({@see queue()}). Nothing
 * else creates one: a company whose list is configured, that is activated
 * or that is added to the audience later gets the announcement only when
 * someone saves it again with the box ticked, and a company that already
 * has a row never gets it twice.
 *
 * The cron (portal:send-announcement-list-emails, every minute) sends a
 * bounded batch of those pending emails once the announcement is published
 * ({@see send()}), from the company's own mailbox with a token of the
 * company's own tenant (an Exchange list only accepts internal senders),
 * always with Microsoft Graph (App\Microsoft\TenantMailSender), never SMTP
 * or the portal's notice mailer; no queue. The row turns «sending» before
 * Graph is called, so a run that dies halfway leaves it «Interrupted»
 * instead of sending twice. Only «Reintentar» ({@see retry()}) sends a
 * failed one again.
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
     * What saving the announcement with the box ticked does: a pending
     * email for each company it addresses that can get it now and has no
     * email of this announcement yet (sent, failed or pending). Returns how
     * many were recorded. The caller has already authorized the save.
     */
    public function queue(Announcement $announcement): int
    {
        if (! $announcement->email_lists) {
            return 0;
        }

        $now = now();
        $rows = $announcement->addressedCompanies()
            ->withListEmail()
            ->whereNotIn('companies.id', $announcement->listEmails()->select('company_id'))
            ->get()
            ->filter(fn (Company $company) => $company->canReceiveListEmail())
            ->map(fn (Company $company) => [
                'announcement_id' => $announcement->id,
                'company_id' => $company->id,
                'status' => AnnouncementListEmail::STATUS_PENDING,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        // A save at the same moment may have recorded one already: the unique pair wins.
        return $rows === [] ? 0 : AnnouncementListEmail::query()->insertOrIgnore($rows);
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
     * announcement addresses now that have an email of it, and, while the
     * box is ticked, those that could get it but were not queued (a save
     * sends it to them). A company it no longer addresses is left out, so
     * an editor of one company never sees another's list.
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
            // Without an email yet, only a company that can really get it (its domains too).
            ->filter(fn (Company $company) => $rows->has($company->id) || $company->canReceiveListEmail())
            ->sortBy(fn (Company $company) => mb_strtolower(self::label($company)));

        $status = $announcement->status;

        return $companies->map(function (Company $company) use ($rows, $announcement, $status) {
            /** @var AnnouncementListEmail|null $row */
            $row = $rows->get($company->id);

            return [
                'row_id' => $row?->id,
                'company' => self::label($company),
                'list' => $company->all_staff_email,
                'state' => match (true) {
                    $row === null => 'not_queued',
                    $row->status !== AnnouncementListEmail::STATUS_PENDING => $row->status,
                    ! $announcement->email_lists => 'off',
                    ! $company->canReceiveListEmail() => 'company_unavailable',
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

            $row = $this->claim((int) $pair->row_id);

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
     *
     * Laravel reads the view as Markdown, so every text of a person stays
     * on one line inside an HTML block: a blank line would end the block
     * and turn the rest into Markdown (a live link or image sent from the
     * company's real mailbox). The body keeps its breaks as <br>; the
     * title and the company name lose theirs.
     */
    public function html(Announcement $announcement, Company $company): string
    {
        // The cron has no request: the link is built from APP_URL.
        $url = rtrim((string) config('app.url'), '/').route('announcements.show', $announcement, false);
        $image = $announcement->imageUrl();
        $body = str_replace(["\r\n", "\r", "\n"], '<br>', e($announcement->body));
        $brand = MailBrand::for($company);

        return (string) app(Markdown::class)->render('mail.announcement-list', [
            'brand' => $brand,
            'companyName' => self::oneLine($brand->name),
            'title' => self::oneLine($announcement->title),
            'body' => new HtmlString($body),
            'imageUrl' => is_string($image) && str_starts_with($image, 'https://') ? $image : null,
            'url' => $url,
            'list' => $company->all_staff_email,
        ]);
    }

    /**
     * The text on one line: every run of line breaks (and the spaces around
     * them) becomes one space.
     */
    public static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s*[\r\n]+\s*/u', ' ', $text));
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
            // Microsoft may have accepted the email before the timeout or the error.
            $error === 'Unavailable' || preg_match('/\AHTTP 5\d\d\z/', $error) === 1 => 'Microsoft no respondió a tiempo o respondió con un error, y no se sabe si salió. Revisa la lista antes de reintentar.',
            $error === AnnouncementListEmail::ERROR_DOMAIN_MISMATCH => 'El buzón o la lista no son de un dominio de la empresa. Corrígelos en Admin › Empresas.',
            default => null,
        };
    }

    public static function label(Company $company): string
    {
        return $company->short_name ?: $company->name;
    }

    /**
     * The pending emails to send now, oldest announcement first: the
     * announcement is published and still asks for it, the company is
     * active, has both addresses and is still addressed. One query.
     *
     * @return Collection<int, object{announcement_id: int, company_id: int, row_id: int}>
     */
    private function due(int $limit): Collection
    {
        return Announcement::query()
            ->inStatus(Announcement::STATUS_PUBLISHED)
            ->where('announcements.email_lists', true)
            ->join('announcement_list_emails as emails', 'emails.announcement_id', '=', 'announcements.id')
            ->where('emails.status', AnnouncementListEmail::STATUS_PENDING)
            ->join('companies', 'companies.id', '=', 'emails.company_id')
            ->where('companies.is_active', true)
            ->whereNotNull('companies.all_staff_email')
            ->whereNotNull('companies.mail_sender')
            ->where(fn (Builder $query) => $query
                ->where('announcements.audience', Announcement::AUDIENCE_ALL)
                ->orWhereExists(fn ($query) => $query
                    ->from('announcement_company')
                    ->whereColumn('announcement_company.announcement_id', 'announcements.id')
                    ->whereColumn('announcement_company.company_id', 'companies.id')))
            ->orderBy('announcements.starts_at')
            ->orderBy('announcements.id')
            ->orderBy('companies.id')
            ->limit($limit)
            ->toBase()
            ->get(['announcements.id as announcement_id', 'companies.id as company_id', 'emails.id as row_id']);
    }

    /**
     * Turns a pending email «sending» before Graph is called. Null when
     * another run claimed it first.
     */
    private function claim(int $rowId): ?AnnouncementListEmail
    {
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
                    self::oneLine($announcement->title),
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
