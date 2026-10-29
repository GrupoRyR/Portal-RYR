<?php

namespace App\Auth;

use App\Audit\SecurityLog;
use App\Models\Person;
use App\Models\User;
use App\Notifications\LinkedAccountNotifier;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Links the accounts one person has in several companies of the group
 * under a Person (at most one account per company) and unlinks them.
 *
 * A link is made only after two Microsoft proofs: a recent
 * re-authentication of the signed-in account and a sign-in to the other
 * one (App\Http\Controllers\Auth\MicrosoftLoginController), never by email
 * or name. It never gives a session and never authorizes anything. Every
 * rule is checked before any write, so a refusal ({@see LinkRefused})
 * changes nothing.
 */
class LinkedAccounts
{
    /** Why an account was unlinked (`account.unlinked`). */
    public const COMPANY_CHANGED = 'company_changed';

    public const ADMIN = 'admin';

    public const USER = 'user';

    public function __construct(private readonly LinkedAccountNotifier $notifier) {}

    /**
     * Every linking rule, without writing anything. The other account may
     * be unsaved: the account a Microsoft sign-in would create.
     *
     * @throws LinkRefused
     */
    public function check(User $current, User $other): void
    {
        if ($this->sameAccount($current, $other)) {
            throw new LinkRefused(LinkRefused::SAME_ACCOUNT);
        }

        $this->checkAccount($current);
        $this->checkAccount($other);

        if ($current->company_id === $other->company_id) {
            throw new LinkRefused(LinkRefused::SAME_COMPANY);
        }

        if ($current->person_id !== null && $other->person_id !== null && $current->person_id !== $other->person_id) {
            throw new LinkRefused(LinkRefused::DIFFERENT_PEOPLE);
        }

        // The account without a person joins the other one's, whose company
        // must still be free (accounts already linked together fail here).
        [$personId, $joining] = $current->person_id !== null
            ? [$current->person_id, $other]
            : [$other->person_id, $current];

        if ($personId !== null && User::query()->where('person_id', $personId)->where('company_id', $joining->company_id)->exists()) {
            throw new LinkRefused(LinkRefused::COMPANY_ALREADY_LINKED);
        }
    }

    /**
     * Links two saved accounts under one person: the person of either
     * account, or a new one. One transaction with the rows locked, where
     * every rule is checked again. Every account of the person is told by
     * mail once it commits.
     *
     * @throws LinkRefused
     */
    public function link(User $current, User $other): Person
    {
        if (! $current->exists || ! $other->exists) {
            throw new LogicException('Only saved accounts can be linked.');
        }

        $person = DB::transaction(function () use ($current, $other) {
            $locked = User::query()
                ->whereKey([$current->getKey(), $other->getKey()])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $current = $locked->get($current->getKey()) ?? throw new LogicException('The account to link is gone.');
            $other = $locked->get($other->getKey()) ?? throw new LogicException('The account to link is gone.');
            $this->lockPeopleOf($current, $other);

            $this->check($current, $other);

            $person = match (true) {
                $current->person_id !== null => Person::query()->findOrFail($current->person_id),
                $other->person_id !== null => Person::query()->findOrFail($other->person_id),
                default => Person::query()->create(),
            };

            foreach ([$current, $other] as $account) {
                if ($account->person_id !== $person->id) {
                    $account->forceFill(['person_id' => $person->id])->save();
                }
            }

            SecurityLog::accountLinked($current, $other, $person->id);
            $this->notifier->notify($person, $current, $other);

            return $person;
        });

        self::remember($current, $person->id);
        self::remember($other, $person->id);

        return $person;
    }

    /**
     * Takes the account out of its person. A person left with one account
     * (or none) is deleted and that last account is no longer linked
     * either. False when the account was not linked.
     *
     * @param  string  $reason  {@see COMPANY_CHANGED}, {@see ADMIN} or {@see User}.
     * @param  User|null  $actor  Who did it; null when the system did.
     */
    public function unlink(User $account, string $reason, ?User $actor = null): bool
    {
        $unlinked = DB::transaction(function () use ($account, $reason, $actor) {
            $locked = User::query()->whereKey($account->getKey())->lockForUpdate()->first();
            $personId = $locked?->person_id;

            if ($personId === null) {
                return false;
            }

            $rest = User::query()
                ->where('person_id', $personId)
                ->whereKeyNot($locked->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $locked->forceFill(['person_id' => null])->save();

            if ($rest->count() <= 1) {
                $rest->each(fn (User $last) => $last->forceFill(['person_id' => null])->save());
                Person::query()->whereKey($personId)->delete();
            }

            SecurityLog::accountUnlinked($locked, $reason, $actor);

            return true;
        });

        if ($unlinked) {
            self::remember($account, null);
        }

        return $unlinked;
    }

    /**
     * For a saved account about to move to another company (its new
     * `company_id` set, not saved yet): when its person already has an
     * account there, the moving account leaves the person
     * ({@see COMPANY_CHANGED}), so that the move itself always goes on.
     */
    public function unlinkIfCompanyTaken(User $account): bool
    {
        if (! $account->exists || $account->person_id === null || ! $account->isDirty('company_id')) {
            return false;
        }

        return DB::transaction(function () use ($account) {
            $taken = User::query()
                ->where('person_id', $account->person_id)
                ->where('company_id', $account->company_id)
                ->whereKeyNot($account->getKey())
                ->lockForUpdate()
                ->get()
                ->isNotEmpty();

            return $taken && $this->unlink($account, self::COMPANY_CHANGED);
        });
    }

    /**
     * Locks the other accounts of the people involved, so that no
     * concurrent link takes one of their companies meanwhile.
     */
    private function lockPeopleOf(User ...$accounts): void
    {
        $people = collect($accounts)->pluck('person_id')->filter()->unique()->values();

        if ($people->isNotEmpty()) {
            User::query()->whereIn('person_id', $people)->orderBy('id')->lockForUpdate()->get();
        }
    }

    /**
     * A saved account is matched by its key; an unsaved one by its
     * Microsoft identity (tenant and object ID).
     */
    private function sameAccount(User $current, User $other): bool
    {
        if ($current->exists && $other->exists && $current->is($other)) {
            return true;
        }

        return $current->tenant_id !== null
            && $current->azure_oid !== null
            && $current->tenant_id === $other->tenant_id
            && $current->azure_oid === $other->azure_oid;
    }

    /**
     * @throws LinkRefused
     */
    private function checkAccount(User $account): void
    {
        if ($account->is_emergency) {
            throw new LinkRefused(LinkRefused::EMERGENCY_ACCOUNT);
        }

        if ($account->tenant_id === null || $account->azure_oid === null) {
            throw new LinkRefused(LinkRefused::NOT_MICROSOFT);
        }

        // Null means not known yet (no sign-in since the flag was added).
        if ($account->is_guest !== false) {
            throw new LinkRefused(LinkRefused::GUEST_ACCOUNT);
        }

        if ($account->company?->is_active !== true) {
            throw new LinkRefused(LinkRefused::COMPANY_INACTIVE);
        }
    }

    /**
     * The caller's model sees the change without being saved again (its
     * other attributes may still be pending).
     */
    private static function remember(User $account, ?int $personId): void
    {
        $account->forceFill(['person_id' => $personId])->syncOriginalAttribute('person_id');
    }
}
