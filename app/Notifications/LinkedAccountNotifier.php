<?php

namespace App\Notifications;

use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Tells every account of a person, each in its own company's brand, that
 * two of its accounts were linked, or that one was unlinked
 * ({@see LinkedAccountNotice}).
 *
 * Sent after the surrounding transaction commits, synchronously. A mail
 * failure never undoes or blocks the change: it is logged instead.
 */
class LinkedAccountNotifier
{
    public function notify(Person $person, User $current, User $other): void
    {
        $notice = LinkedAccountNotice::for($current, $other);

        DB::afterCommit(function () use ($person, $notice) {
            $this->send($person->accounts()->whereNotNull('email')->orderBy('id')->get(), $notice, $person->id);
        });
    }

    /**
     * The recipients are read before the unlink, inside its transaction:
     * the person may be gone once it commits.
     *
     * @param  iterable<User>  $accounts  Every account the person had, the unlinked one included.
     * @param  string  $reason  `user` or `admin` (App\Auth\LinkedAccounts).
     * @param  int  $personId  The person it left, for the log only.
     */
    public function notifyUnlinked(iterable $accounts, User $unlinked, string $reason, int $personId): void
    {
        $notice = LinkedAccountNotice::unlinked($unlinked, $reason);
        $recipients = collect($accounts)->filter(fn (User $account) => $account->email !== null)->values();

        DB::afterCommit(function () use ($recipients, $notice, $personId) {
            $this->send($recipients, $notice, $personId);
        });
    }

    /**
     * @param  iterable<User>  $accounts
     */
    private function send(iterable $accounts, LinkedAccountNotice $notice, int $personId): void
    {
        foreach ($accounts as $account) {
            try {
                Notification::sendNow($account, $notice);
            } catch (Throwable $e) {
                Log::warning('Linked account notice could not be sent.', [
                    'user_id' => $account->id,
                    'person_id' => $personId,
                    'exception' => $e::class,
                ]);
            }
        }
    }
}
