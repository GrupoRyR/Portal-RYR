<?php

namespace Tests\Feature\Console;

use App\Audit\AuditPresenter;
use App\Audit\SecurityLog;
use App\Auth\LinkedAccounts;
use App\Models\Company;
use App\Models\Person;
use App\Models\User;
use App\Notifications\LinkedAccountNotice;
use App\Notifications\LinkedAccountNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * `portal:unlink-account {email}`: a super administrator unlinks an
 * account until Admin › Usuarios exists. The email only finds the row;
 * it never links or authorizes anything.
 */
class UnlinkAccountCommandTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private Company $derivados;

    private User $segurexAccount;

    private User $derivadosAccount;

    private Person $person;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam']);
        $this->derivados = Company::factory()->create(['name' => 'Soluciones y Derivados']);
        $this->segurexAccount = User::factory()->microsoft($this->segurex)->create(['email' => 'edward@segurex.com']);
        $this->derivadosAccount = User::factory()->microsoft($this->derivados)->create(['email' => 'edward@derivados.com']);
        $this->person = $this->personWith($this->segurexAccount, $this->derivadosAccount);
    }

    private function personWith(User ...$accounts): Person
    {
        $person = Person::query()->create();

        foreach ($accounts as $account) {
            $account->forceFill(['person_id' => $person->id])->save();
        }

        return $person;
    }

    private function question(User $account, string $company): string
    {
        return "¿Desvincular la cuenta de {$company} ({$account->email})?";
    }

    public function test_it_unlinks_after_the_confirmation(): void
    {
        $andina = User::factory()->microsoft(Company::factory()->create(['name' => 'Andina Química']))->create();
        $andina->forceFill(['person_id' => $this->person->id])->save();

        $this->artisan('portal:unlink-account', ['email' => 'Edward@Derivados.com'])
            ->expectsConfirmation($this->question($this->derivadosAccount, 'Soluciones y Derivados'), 'yes')
            ->expectsOutputToContain('Cuenta desvinculada: Soluciones y Derivados (edward@derivados.com).')
            ->assertSuccessful();

        $this->assertNull($this->derivadosAccount->fresh()->person_id);
        $this->assertSame($this->person->id, $this->segurexAccount->fresh()->person_id);

        $entry = Activity::query()->where('event', SecurityLog::ACCOUNT_UNLINKED)->sole();
        $this->assertSame(LinkedAccounts::ADMIN, $entry->properties['reason']);
        $this->assertNull($entry->causer, 'A console command has no signed-in user.');
        $this->assertTrue($entry->subject->is($this->derivadosAccount));
        $this->assertSame(
            'Desvinculó la cuenta de Soluciones y Derivados (edward@derivados.com) · por un administrador',
            $this->app->make(AuditPresenter::class)->activity($entry)->what,
        );

        Notification::assertSentTo([$this->segurexAccount, $this->derivadosAccount, $andina], LinkedAccountNotice::class);
    }

    public function test_it_warns_that_the_last_link_dissolves_the_person(): void
    {
        $this->artisan('portal:unlink-account', ['email' => 'edward@derivados.com'])
            ->expectsOutputToContain('La persona queda con una sola cuenta: Segurex Latam (edward@segurex.com) también se desvincula.')
            ->expectsConfirmation($this->question($this->derivadosAccount, 'Soluciones y Derivados'), 'yes')
            ->assertSuccessful();

        $this->assertModelMissing($this->person);
        $this->assertNull($this->segurexAccount->fresh()->person_id);
        $this->assertSame(2, Activity::query()->where('event', SecurityLog::ACCOUNT_UNLINKED)->count());
    }

    public function test_declining_the_confirmation_changes_nothing(): void
    {
        $this->artisan('portal:unlink-account', ['email' => 'edward@derivados.com'])
            ->expectsConfirmation($this->question($this->derivadosAccount, 'Soluciones y Derivados'), 'no')
            ->expectsOutputToContain('No se cambió nada.')
            ->assertFailed();

        $this->assertSame($this->person->id, $this->derivadosAccount->fresh()->person_id);
        $this->assertSame(0, Activity::query()->where('event', SecurityLog::ACCOUNT_UNLINKED)->count());
        Notification::assertNothingSent();
    }

    public function test_force_skips_the_confirmation(): void
    {
        $this->artisan('portal:unlink-account', ['email' => 'edward@derivados.com', '--force' => true])
            ->doesntExpectOutputToContain('¿Desvincular')
            ->expectsOutputToContain('Cuenta desvinculada: Soluciones y Derivados (edward@derivados.com).')
            ->assertSuccessful();

        $this->assertNull($this->derivadosAccount->fresh()->person_id);
    }

    public function test_an_unknown_email_is_refused(): void
    {
        $this->artisan('portal:unlink-account', ['email' => 'nadie@segurex.com', '--force' => true])
            ->expectsOutputToContain('No hay ninguna cuenta con ese correo.')
            ->assertFailed();

        $this->assertSame(0, Activity::query()->where('event', SecurityLog::ACCOUNT_UNLINKED)->count());
    }

    public function test_an_invalid_email_is_refused(): void
    {
        $this->artisan('portal:unlink-account', ['email' => 'no es un correo', '--force' => true])
            ->expectsOutputToContain('El correo no es válido.')
            ->assertFailed();
    }

    public function test_an_account_that_is_not_linked_changes_nothing(): void
    {
        User::factory()->microsoft(Company::factory()->create(['name' => 'Andina Química']))->create(['email' => 'suelto@andina.com']);

        $this->artisan('portal:unlink-account', ['email' => 'suelto@andina.com', '--force' => true])
            ->expectsOutputToContain('Esa cuenta no está vinculada. No se cambió nada.')
            ->assertFailed();

        $this->assertSame(0, Activity::query()->where('event', SecurityLog::ACCOUNT_UNLINKED)->count());
    }

    public function test_several_accounts_with_the_email_need_an_id(): void
    {
        // A B2B guest of the same mailbox in another tenant shares the email.
        $guest = User::factory()->microsoft(Company::factory()->create(['name' => 'Andina Química']))->create([
            'email' => 'edward@derivados.com',
            'is_guest' => true,
        ]);

        $this->artisan('portal:unlink-account', ['email' => 'edward@derivados.com', '--force' => true])
            ->expectsOutputToContain('Hay varias cuentas con ese correo. Elige una con --id=.')
            // One expectation per written line: a line satisfies only one.
            ->expectsOutputToContain('| '.$guest->id.' ')
            ->assertFailed();

        $this->assertSame($this->person->id, $this->derivadosAccount->fresh()->person_id);
    }

    public function test_the_id_picks_one_of_several_accounts(): void
    {
        User::factory()->microsoft(Company::factory()->create())->create(['email' => 'edward@derivados.com', 'is_guest' => true]);

        $this->artisan('portal:unlink-account', ['email' => 'edward@derivados.com', '--id' => (string) $this->derivadosAccount->id, '--force' => true])
            ->expectsOutputToContain('Cuenta desvinculada: Soluciones y Derivados (edward@derivados.com).')
            ->assertSuccessful();

        $this->assertNull($this->derivadosAccount->fresh()->person_id);
    }

    public function test_an_id_that_does_not_match_the_email_is_refused(): void
    {
        $this->artisan('portal:unlink-account', ['email' => 'edward@derivados.com', '--id' => (string) $this->segurexAccount->id, '--force' => true])
            ->expectsOutputToContain('Ninguna cuenta con ese correo tiene el id '.$this->segurexAccount->id.'.')
            ->assertFailed();

        $this->assertSame($this->person->id, $this->segurexAccount->fresh()->person_id);
        $this->assertSame($this->person->id, $this->derivadosAccount->fresh()->person_id);
    }

    public function test_an_account_unlinked_before_the_command_gets_to_it_changes_nothing(): void
    {
        // Someone else unlinks it between the list of accounts and the unlink.
        $this->app->instance(LinkedAccounts::class, new class($this->app->make(LinkedAccountNotifier::class)) extends LinkedAccounts
        {
            public function unlink(User $account, string $reason, ?User $actor = null): bool
            {
                User::query()->whereKey($account->id)->update(['person_id' => null]);

                return parent::unlink($account, $reason, $actor);
            }
        });

        $this->artisan('portal:unlink-account', ['email' => 'edward@derivados.com', '--force' => true])
            ->expectsOutputToContain('La cuenta ya no estaba vinculada. No se cambió nada.')
            ->doesntExpectOutputToContain('Cuenta desvinculada')
            ->assertFailed();

        $this->assertSame($this->person->id, $this->segurexAccount->fresh()->person_id);
        $this->assertSame(0, Activity::query()->where('event', SecurityLog::ACCOUNT_UNLINKED)->count());
        Notification::assertNothingSent();
    }

    public function test_a_person_with_a_single_account_names_no_other_account(): void
    {
        $alone = User::factory()->microsoft(Company::factory()->create(['name' => 'Andina Química']))->create(['email' => 'luis@andina.com']);
        $person = $this->personWith($alone);

        $this->artisan('portal:unlink-account', ['email' => 'luis@andina.com', '--force' => true])
            ->doesntExpectOutputToContain('La persona queda con una sola cuenta')
            ->doesntExpectOutputToContain('()')
            ->expectsOutputToContain('Cuenta desvinculada: Andina Química (luis@andina.com).')
            ->assertSuccessful();

        $this->assertModelMissing($person);
        $this->assertNull($alone->fresh()->person_id);
    }
}
