<?php

namespace App\Digest;

use App\Announcements\AnnouncementListEmails;
use App\Branding\MailBrand;
use App\Microsoft\TenantMailSender;
use App\Models\Company;
use App\Models\WeeklyDigest;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The Monday digest by email to each company's all-staff list
 * (odd/tasks/inicio-fase-2.md WU3, decision of 2026-11-03).
 *
 * Every Monday from 07:00 to the 12:00 run (Bogotá), the cron
 * (portal:send-weekly-digest, every hour in that window) sends one email
 * per active company with «Resumen de los lunes» on and a list and sender
 * at its own domains (Company::canReceiveListEmail()), from its own
 * mailbox with a token of its own tenant, always with Microsoft Graph
 * (App\Microsoft\TenantMailSender), never SMTP or the portal's notice
 * mailer. The content is App\Digest\WeeklyDigestBuilder's.
 *
 * Never twice: one row per company and week (unique pair), written before
 * Graph is called. The first run of the Monday decides each company; a
 * later run only catches up the companies without a row (the switch turned
 * on in the morning, or a run that never happened). Nothing sends a row
 * again: a failed week is not retried (there is no manual resend either),
 * a row left «sending» by a dead run becomes «Interrupted», and a week
 * with nothing to tell is recorded «empty» and not sent. After the noon
 * run nothing goes out for that week.
 */
class WeeklyDigests
{
    /** Mondays are read in Bogotá, whatever the server clock. */
    public const TIMEZONE = 'America/Bogota';

    /** The first run of the Monday. */
    public const FIRST_HOUR = 7;

    /** The last run of the Monday (12:00), even if it starts a few minutes late. */
    public const LAST_HOUR = 12;

    /** A row still «sending» after this many minutes belongs to a run that died. */
    public const STALE_MINUTES = AnnouncementListEmails::STALE_MINUTES;

    public function __construct(
        private readonly TenantMailSender $sender,
        private readonly WeeklyDigestBuilder $builder,
    ) {}

    /**
     * Sends this Monday's digests still to send. Outside the Monday window
     * it only marks interrupted rows. A failure is recorded and logged and
     * never stops the run.
     *
     * @return array{sent: int, empty: int, failed: int}
     */
    public function send(): array
    {
        $this->markInterrupted();

        $result = ['sent' => 0, 'empty' => 0, 'failed' => 0];
        $now = CarbonImmutable::now(self::TIMEZONE);

        if (! self::isSendingTime($now)) {
            return $result;
        }

        $weekStart = $now->startOfDay();

        $companies = Company::query()
            ->withListEmail()
            ->where('companies.weekly_digest_enabled', true)
            ->whereNotIn('companies.id', WeeklyDigest::query()->where('week_start', $weekStart->toDateString())->select('company_id'))
            ->orderBy('companies.id')
            ->get();

        foreach ($companies as $company) {
            $status = $this->deliver($company, $weekStart);

            if ($status !== null) {
                $result[match ($status) {
                    WeeklyDigest::STATUS_SENT => 'sent',
                    WeeklyDigest::STATUS_EMPTY => 'empty',
                    default => 'failed',
                }]++;
            }
        }

        return $result;
    }

    /**
     * Monday from FIRST_HOUR to the end of LAST_HOUR, in Bogotá.
     */
    public static function isSendingTime(CarbonInterface $moment): bool
    {
        $local = $moment->copy()->setTimezone(self::TIMEZONE);

        return $local->isMonday() && $local->hour >= self::FIRST_HOUR && $local->hour <= self::LAST_HOUR;
    }

    /**
     * The HTML of the digest for the company's list: its brand, the week,
     * each section with something in it and «Abrir el portal».
     *
     * Laravel reads the view as Markdown, so every text of a person stays
     * on one line inside an HTML block (the builder takes the line breaks
     * out): a blank line would end the block and turn the rest into
     * Markdown (a live link sent from the company's real mailbox).
     */
    public function html(Company $company, WeeklyDigestContent $content): string
    {
        $brand = MailBrand::for($company);

        return (string) app(Markdown::class)->render('mail.weekly-digest', [
            'brand' => $brand,
            'companyName' => AnnouncementListEmails::oneLine($brand->name),
            'range' => $content->range(),
            'announcements' => $content->announcements,
            'dates' => $content->dates,
            'celebrations' => $content->celebrations,
            // The cron has no request: the link is built from APP_URL.
            'url' => rtrim((string) config('app.url'), '/'),
            'list' => $company->all_staff_email,
        ]);
    }

    /**
     * How the company's last digest went, for Admin › Empresas: the
     * status line and, for a failure, the Spanish hint of its code (the
     * list emails' hints).
     *
     * @return array{text: string, hint: string|null, failed: bool}
     */
    public static function describe(?WeeklyDigest $row): array
    {
        if ($row === null) {
            return ['text' => 'Aún no se ha enviado.', 'hint' => null, 'failed' => false];
        }

        $week = $row->week_start->copy()->locale('es');

        return match (true) {
            $row->status === WeeklyDigest::STATUS_SENT => [
                'text' => 'Enviado el '.($row->sent_at ?? $row->updated_at)->copy()->setTimezone(self::TIMEZONE)->locale('es')->isoFormat('dddd D [de] MMMM, HH:mm').'.',
                'hint' => null,
                'failed' => false,
            ],
            $row->status === WeeklyDigest::STATUS_EMPTY => [
                'text' => 'Sin contenido: la semana del '.$week->isoFormat('D [de] MMMM').' no tenía nada que contar y no se envió.',
                'hint' => null,
                'failed' => false,
            ],
            // A dead run's row only turns «Interrupted» at the next run (the next Monday): say it now.
            $row->status === WeeklyDigest::STATUS_SENDING && $row->updated_at->gte(now()->subMinutes(self::STALE_MINUTES)) => ['text' => 'Enviando…', 'hint' => null, 'failed' => false],
            $row->status === WeeklyDigest::STATUS_SENDING || $row->error_code === WeeklyDigest::ERROR_INTERRUPTED => [
                'text' => 'Interrumpido: el envío del '.$week->isoFormat('dddd D [de] MMMM').' se cortó y no se sabe si salió.',
                'hint' => 'Revisa la lista para saber si llegó.',
                'failed' => true,
            ],
            default => [
                'text' => 'No se pudo enviar ('.($row->error_code ?? 'Unexpected').').',
                'hint' => AnnouncementListEmails::hint($row->error_code),
                'failed' => true,
            ],
        };
    }

    /**
     * One company: builds its digest, records the week before calling
     * Graph and records the answer. Null when another run took the week.
     */
    private function deliver(Company $company, CarbonImmutable $weekStart): ?string
    {
        try {
            $content = $this->builder->build($company, $weekStart);
            $html = $content->isEmpty() ? null : $this->html($company, $content);
        } catch (Throwable $e) {
            // No row: the next run of the morning tries again.
            Log::error('Weekly digest could not be built.', ['company_id' => $company->id, 'exception' => $e::class]);

            return null;
        }

        $status = match (true) {
            ! $company->canReceiveListEmail() => WeeklyDigest::STATUS_FAILED,
            $html === null => WeeklyDigest::STATUS_EMPTY,
            default => WeeklyDigest::STATUS_SENDING,
        };
        $error = $status === WeeklyDigest::STATUS_FAILED ? WeeklyDigest::ERROR_DOMAIN_MISMATCH : null;
        $exception = null;

        $now = now();
        // The unique pair: a run at the same moment may have taken the week already.
        $claimed = WeeklyDigest::query()->insertOrIgnore([
            'company_id' => $company->id,
            'week_start' => $weekStart->toDateString(),
            'status' => $status,
            'error_code' => $error,
            'attempted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($claimed !== 1) {
            return null;
        }

        if ($status === WeeklyDigest::STATUS_SENDING) {
            try {
                $this->sender->send(
                    (string) $company->tenant_id,
                    (string) $company->mail_sender,
                    (string) $company->all_staff_email,
                    $content->subject(),
                    (string) $html,
                );
            } catch (Throwable $e) {
                $exception = $e;
                $error = AnnouncementListEmails::errorCode($e);
            }

            $status = $error === null ? WeeklyDigest::STATUS_SENT : WeeklyDigest::STATUS_FAILED;

            WeeklyDigest::query()
                ->where('company_id', $company->id)
                ->where('week_start', $weekStart->toDateString())
                ->update([
                    'status' => $status,
                    'error_code' => $error,
                    'sent_at' => $error === null ? now() : null,
                    'updated_at' => now(),
                ]);
        }

        if ($error !== null) {
            Log::warning('Weekly digest could not be sent.', [
                'company_id' => $company->id,
                'week_start' => $weekStart->toDateString(),
                'error' => $error,
                'exception' => $exception === null ? null : $exception::class,
            ]);
        }

        return $status;
    }

    /**
     * Rows a dead run left «sending»: it is not known whether they left,
     * so they fail as «Interrupted» and are never sent again.
     */
    private function markInterrupted(): void
    {
        WeeklyDigest::query()
            ->where('status', WeeklyDigest::STATUS_SENDING)
            ->where('updated_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->update(['status' => WeeklyDigest::STATUS_FAILED, 'error_code' => WeeklyDigest::ERROR_INTERRUPTED]);
    }
}
