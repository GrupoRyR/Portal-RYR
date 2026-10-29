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
 * two of its accounts were linked ({@see LinkedAccountNotice}).
 *
 * Sent after the surrounding transaction commits, synchronously. A mail
 * failure never undoes or blocks the link: it is logged instead.
 */
class LinkedAccountNotifier
{
    public function notify(Person $person, User $current, User $other): void
    {
        $notice = LinkedAccountNotice::for($current, $other);

        DB::afterCommit(function () use ($person, $notice) {
            foreach ($person->accounts()->whereNotNull('email')->orderBy('id')->get() as $account) {
                try {
                    Notification::sendNow($account, $notice);
                } catch (Throwable $e) {
                    Log::warning('Linked account notice could not be sent.', [
                        'user_id' => $account->id,
                        'person_id' => $person->id,
                        'exception' => $e::class,
                    ]);
                }
            }
        });
    }
}
