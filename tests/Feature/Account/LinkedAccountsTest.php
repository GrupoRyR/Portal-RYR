<?php

namespace Tests\Feature\Account;

use App\Audit\AuditPresenter;
use App\Audit\SecurityLog;
use App\Auth\LinkedAccounts;
use App\Auth\LinkRefused;
use App\Models\Company;
use App\Models\Person;
use App\Models\User;
use App\Notifications\LinkedAccountNotifier;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The accounts of one person (App\Auth\LinkedAccounts): one account per
 * company, joined only through this class, and a refusal changes nothing.
 */
class LinkedAccountsTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private Company $derivados;

    protected function setUp(): void
    {
        parent::setUp();

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam']);
        $this->derivados = Company::factory()->create(['name' => 'Soluciones y Derivados']);
    }

    private function linkedAccounts(): LinkedAccounts
    {
        return $this->app->make(LinkedAccounts::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function account(Company $company, array $attributes = []): User
    {
        return User::factory()->microsoft($company)->create($attributes);
    }

    private function personWith(User ...$accounts): Person
    {
        $person = Person::create();

        foreach ($accounts as $account) {
            $account->forceFill(['person_id' => $person->id])->save();
        }

        return $person;
    }

    /**
     * @return array<int, int|null>
     */
    private function personIds(): array
    {
        return User::query()->orderBy('id')->pluck('person_id', 'id')->all();
    }

    private function assertRefused(string $reason, User $current, User $other): void
    {
        $people = Person::query()->orderBy('id')->pluck('id')->all();
        $users = $this->personIds();

        try {
            $this->linkedAccounts()->link($current, $other);
            $this->fail("The link should have been refused ({$reason}).");
        } catch (LinkRefused $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertSame($people, Person::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame($users, $this->personIds());
        $this->assertSame(0, Activity::query()->where('event', SecurityLog::ACCOUNT_LINKED)->count());
    }

    // --- Linking -----------------------------------------------------------

    public function test_two_accounts_without_a_person_get_a_new_one(): void
    {
        $current = $this->account($this->segurex);
        $other = $this->account($this->derivados);

        $person = $this->linkedAccounts()->link($current, $other);

        $this->assertSame(1, Person::count());
        $this->assertSame($person->id, $current->fresh()->person_id);
        $this->assertSame($person->id, $other->fresh()->person_id);
        $this->assertSame($person->id, $current->person_id, 'The given models see the link too.');
        $this->assertEqualsCanonicalizing([$current->id, $other->id], $person->accounts()->pluck('id')->all());
    }

    public function test_the_other_account_joins_the_person_of_the_signed_in_account(): void
    {
        $andina = Company::factory()->create();
        $current = $this->account($this->segurex);
        $person = $this->personWith($current, $this->account($andina));
        $other = $this->account($this->derivados);

        $this->assertTrue($person->is($this->linkedAccounts()->link($current, $other)));

        $this->assertSame(1, Person::count());
        $this->assertSame($person->id, $other->fresh()->person_id);
    }

    public function test_the_signed_in_account_joins_the_person_of_the_other_account(): void
    {
        $andina = Company::factory()->create();
        $other = $this->account($this->derivados);
        $person = $this->personWith($other, $this->account($andina));
        $current = $this->account($this->segurex);

        $this->assertTrue($person->is($this->linkedAccounts()->link($current, $other)));

        $this->assertSame(1, Person::count());
        $this->assertSame($person->id, $current->fresh()->person_id);
    }

    public function test_a_person_links_one_account_in_each_of_three_companies(): void
    {
        $andina = Company::factory()->create();
        $segurex = $this->account($this->segurex);
        $derivados = $this->account($this->derivados);
        $third = $this->account($andina);

        $first = $this->linkedAccounts()->link($segurex, $derivados);
        $second = $this->linkedAccounts()->link($segurex, $third);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Person::count());
        $this->assertEqualsCanonicalizing([$derivados->id, $third->id], $segurex->fresh()->linkedAccounts()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$segurex->id, $derivados->id], $third->fresh()->linkedAccounts()->pluck('id')->all());
    }

    public function test_linking_is_a_security_event_of_the_signed_in_account(): void
    {
        $current = $this->account($this->segurex);
        $other = $this->account($this->derivados, ['email' => 'edward@derivados.com']);

        $person = $this->linkedAccounts()->link($current, $other);

        $entry = Activity::query()->where('log_name', SecurityLog::LOG)->sole();
        $this->assertSame(SecurityLog::ACCOUNT_LINKED, $entry->event);
        $this->assertTrue($entry->causer->is($current));
        $this->assertTrue($entry->subject->is($other));
        $this->assertSame('Soluciones y Derivados', $entry->properties['linked_company']);
        $this->assertSame('edward@derivados.com', $entry->properties['linked_email']);
        $this->assertSame($person->id, $entry->properties['person_id']);
        $this->assertSame(
            'Vinculó su cuenta de Soluciones y Derivados (edward@derivados.com)',
            $this->app->make(AuditPresenter::class)->activity($entry)->what,
        );
    }

    // --- Refusals ----------------------------------------------------------

    public function test_the_same_account_is_refused(): void
    {
        $current = $this->account($this->segurex);

        $this->assertRefused(LinkRefused::SAME_ACCOUNT, $current, $current->fresh());
    }

    public function test_an_unsaved_copy_of_the_same_microsoft_account_is_refused(): void
    {
        $current = $this->account($this->segurex);
        $copy = (new User)->forceFill([
            'tenant_id' => strtoupper($current->tenant_id),
            'azure_oid' => strtoupper($current->azure_oid),
            'company_id' => $this->derivados->id,
            'is_guest' => false,
        ]);

        $this->expectExceptionObject(new LinkRefused(LinkRefused::SAME_ACCOUNT));

        $this->linkedAccounts()->check($current, $copy);
    }

    public function test_an_emergency_account_is_refused_on_either_side(): void
    {
        $emergency = User::factory()->emergency()->create();
        $account = $this->account($this->segurex);

        $this->assertRefused(LinkRefused::EMERGENCY_ACCOUNT, $emergency, $account);
        $this->assertRefused(LinkRefused::EMERGENCY_ACCOUNT, $account, $emergency);
    }

    public function test_an_account_without_a_microsoft_identity_is_refused(): void
    {
        $local = User::factory()->create(['company_id' => $this->derivados->id]);

        $this->assertRefused(LinkRefused::NOT_MICROSOFT, $this->account($this->segurex), $local);
    }

    public function test_a_guest_account_is_refused_on_either_side(): void
    {
        $guest = $this->account($this->segurex, ['is_guest' => true]);
        $home = $this->account($this->derivados);

        $this->assertRefused(LinkRefused::GUEST_ACCOUNT, $guest, $home);
        $this->assertRefused(LinkRefused::GUEST_ACCOUNT, $home, $guest);
    }

    public function test_an_account_not_known_to_be_a_home_account_is_refused(): void
    {
        // No sign-in since the guest flag was added: not known, so refused
        // until the account confirms with Microsoft again.
        $unknown = $this->account($this->segurex, ['is_guest' => null]);

        $this->assertRefused(LinkRefused::GUEST_UNKNOWN, $unknown, $this->account($this->derivados));
        $this->assertSame('Confirma de nuevo tu cuenta y vuelve a intentarlo.', (new LinkRefused(LinkRefused::GUEST_UNKNOWN))->getMessage());
    }

    public function test_an_inactive_company_is_refused_on_either_side(): void
    {
        $closed = Company::factory()->inactive()->create();
        $inactive = $this->account($closed);
        $active = $this->account($this->segurex);

        $this->assertRefused(LinkRefused::COMPANY_INACTIVE, $active, $inactive);
        $this->assertRefused(LinkRefused::COMPANY_INACTIVE, $inactive, $active);
    }

    public function test_an_account_without_a_company_is_refused(): void
    {
        $this->assertRefused(
            LinkRefused::COMPANY_INACTIVE,
            $this->account($this->segurex),
            $this->account($this->derivados, ['company_id' => null]),
        );
    }

    public function test_two_accounts_of_the_same_company_are_refused(): void
    {
        $this->assertRefused(
            LinkRefused::SAME_COMPANY,
            $this->account($this->segurex),
            $this->account($this->segurex),
        );
    }

    public function test_a_company_the_person_already_has_is_refused(): void
    {
        $current = $this->account($this->segurex);
        $this->personWith($current, $this->account($this->derivados));

        $this->assertRefused(LinkRefused::COMPANY_ALREADY_LINKED, $current, $this->account($this->derivados));
    }

    public function test_joining_a_person_that_already_has_the_signed_in_company_is_refused(): void
    {
        $other = $this->account($this->derivados);
        $this->personWith($other, $this->account($this->segurex));

        $this->assertRefused(LinkRefused::COMPANY_ALREADY_LINKED, $this->account($this->segurex), $other);
    }

    public function test_accounts_already_linked_together_are_refused(): void
    {
        $current = $this->account($this->segurex);
        $other = $this->account($this->derivados);
        $this->personWith($current, $other);

        $this->assertRefused(LinkRefused::COMPANY_ALREADY_LINKED, $current, $other);
    }

    public function test_accounts_of_different_people_are_refused(): void
    {
        $andina = Company::factory()->create();
        $current = $this->account($this->segurex);
        $other = $this->account($this->derivados);
        $this->personWith($current, $this->account($andina));
        $this->personWith($other, $this->account(Company::factory()->create()));

        $this->assertRefused(LinkRefused::DIFFERENT_PEOPLE, $current, $other);
    }

    public function test_the_database_keeps_one_account_per_company_per_person(): void
    {
        $person = $this->personWith($this->account($this->segurex));

        $this->expectException(QueryException::class);

        $this->account($this->segurex)->forceFill(['person_id' => $person->id])->save();
    }

    public function test_the_person_cannot_be_mass_assigned(): void
    {
        $person = Person::create();
        $account = $this->account($this->segurex);

        $account->fill(['person_id' => $person->id])->save();

        $this->assertNull($account->fresh()->person_id);
    }

    // --- Unlinking ---------------------------------------------------------

    public function test_unlinking_deletes_a_person_left_with_one_account(): void
    {
        $current = $this->account($this->segurex);
        $other = $this->account($this->derivados);
        $person = $this->personWith($current, $other);

        $this->assertTrue($this->linkedAccounts()->unlink($other, LinkedAccounts::USER, $current));

        $this->assertModelMissing($person);
        $this->assertNull($current->fresh()->person_id);
        $this->assertNull($other->fresh()->person_id);
        $this->assertNull($other->person_id, 'The given model sees the change too.');
    }

    public function test_unlinking_keeps_a_person_with_two_accounts_left(): void
    {
        $current = $this->account($this->segurex);
        $other = $this->account($this->derivados);
        $third = $this->account(Company::factory()->create());
        $person = $this->personWith($current, $other, $third);

        $this->linkedAccounts()->unlink($third, LinkedAccounts::ADMIN);

        $this->assertModelExists($person);
        $this->assertNull($third->fresh()->person_id);
        $this->assertSame([$other->id], $current->fresh()->linkedAccounts()->pluck('id')->all());
    }

    public function test_unlinking_is_a_security_event_with_its_reason(): void
    {
        $current = $this->account($this->segurex);
        $other = $this->account($this->derivados, ['email' => 'edward@derivados.com']);
        $this->personWith($current, $other);

        $this->linkedAccounts()->unlink($other, LinkedAccounts::COMPANY_CHANGED);

        $entry = Activity::query()->where('log_name', SecurityLog::LOG)->where('subject_id', $other->id)->sole();
        $this->assertSame(SecurityLog::ACCOUNT_UNLINKED, $entry->event);
        $this->assertNull($entry->causer, 'An automatic unlink has no causer.');
        $this->assertTrue($entry->subject->is($other));
        $this->assertSame(LinkedAccounts::COMPANY_CHANGED, $entry->properties['reason']);
        $this->assertSame('Soluciones y Derivados', $entry->properties['company']);
        $this->assertSame('edward@derivados.com', $entry->properties['email']);

        $row = $this->app->make(AuditPresenter::class)->activity($entry);
        $this->assertSame(AuditPresenter::SYSTEM, $row->who);
        $this->assertSame('Desvinculó la cuenta de Soluciones y Derivados (edward@derivados.com) · por cambio de empresa', $row->what);
    }

    public function test_dissolving_a_person_records_its_last_account_as_unlinked_too(): void
    {
        $current = $this->account($this->segurex);
        $other = $this->account($this->derivados);
        $this->personWith($current, $other);

        $this->linkedAccounts()->unlink($other, LinkedAccounts::USER, $current);

        $entries = Activity::query()->where('event', SecurityLog::ACCOUNT_UNLINKED)->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertEqualsCanonicalizing([$other->id, $current->id], $entries->pluck('subject_id')->all());

        foreach ($entries as $entry) {
            $this->assertSame(LinkedAccounts::USER, $entry->properties['reason']);
            $this->assertTrue($entry->causer->is($current));
        }
    }

    public function test_unlinking_one_of_three_accounts_records_only_that_one(): void
    {
        $current = $this->account($this->segurex);
        $other = $this->account($this->derivados);
        $this->personWith($current, $other, $this->account(Company::factory()->create()));

        $this->linkedAccounts()->unlink($other, LinkedAccounts::ADMIN);

        $this->assertSame([$other->id], Activity::query()->where('event', SecurityLog::ACCOUNT_UNLINKED)->pluck('subject_id')->all());
    }

    /**
     * The rules read the person's accounts, but a concurrent link may
     * commit between that read and the write. The unique index stops the
     * second account in that company, and the link is refused as such.
     */
    public function test_a_link_the_rules_missed_is_stopped_by_the_unique_index(): void
    {
        $current = $this->account($this->segurex);
        $this->personWith($current, $this->account($this->derivados));
        $other = $this->account($this->derivados);
        $blind = new class($this->app->make(LinkedAccountNotifier::class)) extends LinkedAccounts
        {
            public function check(User $current, User $other): void {}
        };
        $users = $this->personIds();

        try {
            $blind->link($current, $other);
            $this->fail('The unique index should have stopped the link.');
        } catch (LinkRefused $e) {
            $this->assertSame(LinkRefused::COMPANY_ALREADY_LINKED, $e->reason);
        }

        $this->assertSame($users, $this->personIds());
        $this->assertSame(0, Activity::query()->where('event', SecurityLog::ACCOUNT_LINKED)->count());
    }

    public function test_unlinking_an_account_without_a_person_does_nothing(): void
    {
        $account = $this->account($this->segurex);

        $this->assertFalse($this->linkedAccounts()->unlink($account, LinkedAccounts::ADMIN));

        $this->assertSame(0, Activity::query()->where('log_name', SecurityLog::LOG)->count());
    }

    public function test_an_account_without_a_person_has_no_linked_accounts(): void
    {
        $this->account($this->derivados);

        $this->assertCount(0, $this->account($this->segurex)->linkedAccounts);
    }
}
