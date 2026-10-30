<?php

namespace App\Announcements;

use App\Models\Announcement;
use App\Models\AnnouncementReminder;
use App\Models\AnnouncementReminderDelivery;
use App\Models\User;
use App\Notifications\AnnouncementReminderNotice;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Email reminders to the people who have not confirmed a mandatory
 * announcement (odd/tasks/ajustes-inicio-y-tareas.md T5).
 *
 * «Enviar recordatorio» ({@see request()}) only records the request, at
 * most one every AnnouncementReminder::INTERVAL_HOURS per announcement.
 * The cron (portal:send-announcement-reminders, every minute) mails a
 * bounded batch ({@see send()}): one email per person who is still
 * missing at send time, to one of their accounts in the audience, from the
 * portal's mailbox (the Graph mailer). No queue: shared hosting has no
 * worker.
 */
class AnnouncementReminders
{
    /** Emails per run of the cron, across every pending reminder. */
    public const BATCH = 50;

    /** Name of the named audit entry of a request. */
    public const AUDIT_EVENT = 'announcement.reminder_requested';

    /**
     * Records a reminder of a published mandatory announcement with people
     * missing, unless one was requested in the last INTERVAL_HOURS. The
     * announcement row is locked, so two requests at once (a double click,
     * two editors) wait for each other and the second one is refused.
     * Whether the user may edit the announcement is the caller's job.
     *
     * @throws ReminderNotAllowed
     */
    public function request(Announcement $announcement, User $requester): AnnouncementReminder
    {
        return DB::transaction(function () use ($announcement, $requester) {
            $locked = Announcement::query()->whereKey($announcement->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $locked->requires_read || $locked->status !== Announcement::STATUS_PUBLISHED) {
                throw ReminderNotAllowed::notShown();
            }

            // A stopped reminder does not count: the announcement may be back.
            $last = $this->latestOf($locked, countingOnly: true);

            if ($last !== null && $last->nextAllowedAt()->isFuture()) {
                throw ReminderNotAllowed::tooSoon($last->nextAllowedAt());
            }

            $counts = $locked->readCounts();
            $missing = $counts['total'] - $counts['read'];

            if ($missing <= 0) {
                throw ReminderNotAllowed::nobodyMissing();
            }

            $reminder = $locked->reminders()->create([
                'requested_by' => $requester->getKey(),
                'requested_at' => now(),
                'missing_count' => $missing,
            ]);

            activity(Announcement::AUDIT_LOG)
                ->performedOn($locked)
                ->causedBy($requester)
                ->event(self::AUDIT_EVENT)
                ->withProperties(['attributes' => ['missing_people' => $missing]])
                ->log(self::AUDIT_EVENT);

            return $reminder;
        });
    }

    /**
     * The last reminder of the announcement, for the read report: when and
     * by whom it was requested, to how many people it was sent so far,
     * whether the cron finished it or it was stopped, and when the next one
     * may be requested (null: now, as far as the limit goes).
     *
     * @return array{requested_at: CarbonInterface, requester: string|null, sent: int, finished: bool, stopped: bool, next_allowed_at: CarbonInterface|null}|null
     */
    public function lastFor(Announcement $announcement): ?array
    {
        $reminder = $this->latestOf($announcement, withDetails: true);

        if ($reminder === null) {
            return null;
        }

        $counting = $reminder->stopped_at === null ? $reminder : $this->latestOf($announcement, countingOnly: true);

        return [
            'requested_at' => $reminder->requested_at->toImmutable(),
            'requester' => $reminder->requester?->name,
            'sent' => (int) $reminder->getAttribute('sent_count'),
            'finished' => $reminder->finished_at !== null,
            'stopped' => $reminder->stopped_at !== null,
            'next_allowed_at' => $counting?->nextAllowedAt()->toImmutable(),
        ];
    }

    /**
     * Mails up to $limit people, oldest request first. A reminder whose
     * announcement is no longer published and mandatory is stopped without
     * mailing anyone else; one with nobody left to mail is finished.
     * «Nobody left» is decided from the people picked
     * ({@see Announcement::reminderCandidates()}), not from the accounts
     * loaded after it, so an account deleted in between does not end the
     * reminder early.
     *
     * @return array{sent: int, failed: int}
     */
    public function send(int $limit = self::BATCH): array
    {
        $result = ['sent' => 0, 'failed' => 0];

        $reminders = AnnouncementReminder::query()
            ->unfinished()
            ->with('announcement')
            ->orderBy('requested_at')
            ->orderBy('id')
            ->get();

        foreach ($reminders as $reminder) {
            if ($limit <= 0) {
                break;
            }

            $announcement = $reminder->announcement;

            if ($announcement === null || ! $announcement->requires_read || $announcement->status !== Announcement::STATUS_PUBLISHED) {
                $this->finish($reminder, stopped: true);

                continue;
            }

            $candidates = $announcement->reminderCandidates($reminder, $limit);
            $exhausted = $candidates->count() < $limit;
            $limit -= $candidates->count();

            $accounts = User::query()->whereKey($candidates->keys())->with('company')->get()->keyBy('id');

            foreach ($candidates as $accountId => $personKey) {
                // Deleted since it was picked: the next run picks again.
                $account = $accounts->get($accountId);

                if ($account === null) {
                    continue;
                }

                $delivery = $this->claim($reminder, $personKey, $account);

                if ($delivery === null) {
                    continue;
                }

                $result[$this->deliver($delivery, $announcement, $account) ? 'sent' : 'failed']++;
            }

            // Fewer picked than asked for: nobody is left for this reminder.
            if ($exhausted) {
                $this->finish($reminder);
            }
        }

        return $result;
    }

    /**
     * «30 de octubre a las 10:00», in the portal's time zone.
     */
    public static function when(CarbonInterface $moment): string
    {
        return $moment->copy()->timezone(config('app.timezone'))->locale('es')->isoFormat('D [de] MMMM [a las] HH:mm');
    }

    /**
     * @param  bool  $countingOnly  Leave out stopped reminders: only the others count for the limit.
     */
    private function latestOf(Announcement $announcement, bool $withDetails = false, bool $countingOnly = false): ?AnnouncementReminder
    {
        return $announcement->reminders()
            ->when($countingOnly, fn ($query) => $query->whereNull('stopped_at'))
            ->when($withDetails, fn ($query) => $query
                ->with('requester:id,name')
                ->withCount(['deliveries as sent_count' => fn ($query) => $query->where('status', AnnouncementReminderDelivery::STATUS_SENT)]))
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Records the person for the reminder before mailing them. Null when a
     * row already exists (another run got there first): the unique index
     * on (reminder, person) is what keeps a person from a second email.
     */
    private function claim(AnnouncementReminder $reminder, int $personKey, User $account): ?AnnouncementReminderDelivery
    {
        try {
            return $reminder->deliveries()->create([
                'person_key' => $personKey,
                'user_id' => $account->getKey(),
                'status' => AnnouncementReminderDelivery::STATUS_PENDING,
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * A mail failure is logged and recorded, never retried, and never
     * stops the batch.
     */
    private function deliver(AnnouncementReminderDelivery $delivery, Announcement $announcement, User $account): bool
    {
        try {
            Notification::sendNow($account, new AnnouncementReminderNotice($announcement));
            $status = AnnouncementReminderDelivery::STATUS_SENT;
        } catch (Throwable $e) {
            Log::warning('Announcement reminder could not be sent.', [
                'reminder_id' => $delivery->announcement_reminder_id,
                'announcement_id' => $announcement->id,
                'user_id' => $account->id,
                'exception' => $e::class,
            ]);
            $status = AnnouncementReminderDelivery::STATUS_FAILED;
        }

        $delivery->forceFill(['status' => $status])->save();

        return $status === AnnouncementReminderDelivery::STATUS_SENT;
    }

    /**
     * @param  bool  $stopped  The announcement no longer asks for confirmation.
     */
    private function finish(AnnouncementReminder $reminder, bool $stopped = false): void
    {
        $reminder->forceFill(['finished_at' => now(), 'stopped_at' => $stopped ? now() : null])->save();
    }
}
