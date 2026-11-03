<?php

namespace App\Auth;

use App\Audit\SecurityLog;
use App\Models\Person;
use App\Models\User;
use App\Notifications\LinkedAccountNotifier;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
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
 *
 * Writes lock the accounts involved and every account of their people in
 * one statement, by id, so that linking and unlinking take their locks in
 * the same order.
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
        // A locking read: inside link() it sees what other transactions
        // committed, not the snapshot of an earlier read.
        [$personId, $joining] = $current->person_id !== null
            ? [$current->person_id, $other]
            : [$other->person_id, $current];

        $taken = $personId !== null && User::query()
            ->where('person_id', $personId)
            ->where('company_id', $joining->company_id)
            ->lockForUpdate()
            ->first() !== null;

        if ($taken) {
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
            $locked = $this->lockWithPeople($current->getKey(), $other->getKey());

            $current = $locked->get($current->getKey()) ?? throw new LogicException('The account to link is gone.');
            $other = $locked->get($other->getKey()) ?? throw new LogicException('The account to link is gone.');

            $this->check($current, $other);

            $person = match (true) {
                $current->person_id !== null => Person::query()->findOrFail($current->person_id),
                $other->person_id !== null => Person::query()->findOrFail($other->person_id),
                default => Person::query()->create(),
            };

            try {
                foreach ([$current, $other] as $account) {
                    if ($account->person_id !== $person->id) {
                        $account->forceFill(['person_id' => $person->id])->save();
                    }
                }
            } catch (UniqueConstraintViolationException) {
                // UNIQUE(person_id, company_id): a concurrent link took the
                // company after the rules were read.
                throw new LinkRefused(LinkRefused::COMPANY_ALREADY_LINKED);
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
     * Whether the signed-in account may switch to the target one: another
     * account of the same person, a Microsoft home account (not a guest,
     * not an emergency account) of an active company. This is the only
     * thing the person decides; the switch itself is still a Microsoft
     * sign-in with the target account, which checks everything a sign-in
     * checks.
     */
    public function canSwitch(User $current, User $target): bool
    {
        return $current->exists
            && $target->exists
            && ! $current->is($target)
            && ! $current->is_emergency
            && $current->person_id !== null
            && $current->person_id === $target->person_id
            && ! $target->is_emergency
            && $target->tenant_id !== null
            && $target->azure_oid !== null
            // Null means not known yet: refused like a guest.
            && $target->is_guest === false
            && $target->company?->is_active === true;
    }

    /**
     * Takes the account out of its person. A person left with one account
     * (or none) is deleted and that last account is unlinked too; each
     * unlinked account is a security event. False when the account was not
     * linked.
     *
     * @param  string  $reason  {@see COMPANY_CHANGED}, {@see ADMIN} or {@see User}.
     * @param  User|null  $actor  Who did it; null when the system did.
     */
    public function unlink(User $account, string $reason, ?User $actor = null): bool
    {
        return $this->detach($account, $reason, $actor);
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

        $companyId = $account->company_id;

        return $this->detach(
            $account,
            self::COMPANY_CHANGED,
            null,
            fn (Collection $rest) => $rest->contains(fn (User $other) => $other->company_id === $companyId),
        );
    }

    /**
     * @param  (Closure(Collection<int, User>): bool)|null  $only  Unlinks only when it holds for the person's other accounts (locked).
     */
    private function detach(User $account, string $reason, ?User $actor, ?Closure $only = null): bool
    {
        if (! $account->exists) {
            return false;
        }

        $unlinked = DB::transaction(function () use ($account, $reason, $actor, $only) {
            $target = $this->lockWithPeople($account->getKey())->get($account->getKey());
            $personId = $target?->person_id;

            if ($personId === null) {
                return false;
            }

            // Already locked above, unless the person changed in between.
            $rest = User::query()
                ->where('person_id', $personId)
                ->whereKeyNot($target->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($only !== null && ! $only($rest)) {
                return false;
            }

            $target->forceFill(['person_id' => null])->save();
            SecurityLog::accountUnlinked($target, $reason, $actor);

            if ($rest->count() <= 1) {
                foreach ($rest as $last) {
                    $last->forceFill(['person_id' => null])->save();
                    SecurityLog::accountUnlinked($last, $reason, $actor);
                }

                Person::query()->whereKey($personId)->delete();
            }

            return true;
        });

        if ($unlinked) {
            self::remember($account, null);
        }

        return $unlinked;
    }

    /**
     * Locks the given accounts and every account of their people in one
     * statement, by id. The people are read first without a lock; if one
     * changes before the lock, the rules and the unique index, both run
     * on locked rows afterwards, still catch it.
     *
     * @return Collection<int, User> keyed by id
     */
    private function lockWithPeople(int ...$ids): Collection
    {
        $people = User::query()->whereKey($ids)->whereNotNull('person_id')->distinct()->pluck('person_id')->all();

        return User::query()
            ->where(fn (Builder $query) => $query->whereKey($ids)->orWhereIn('person_id', $people))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
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

        // Null means not known yet: the next confirmation records it.
        if ($account->is_guest === null) {
            throw new LinkRefused(LinkRefused::GUEST_UNKNOWN);
        }

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
