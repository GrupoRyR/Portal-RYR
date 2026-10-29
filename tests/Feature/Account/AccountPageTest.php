<?php

namespace Tests\Feature\Account;

use App\Audit\AuditPresenter;
use App\Audit\SecurityLog;
use App\Auth\LinkedAccounts;
use App\Auth\RecentMicrosoftLogin;
use App\Models\Company;
use App\Models\Person;
use App\Models\User;
use App\Notifications\LinkedAccountNotice;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * «Cuentas vinculadas» (GET /cuenta) and unlinking from it: a
 * confirmation page, then POST /cuenta/desvincular/{user}, both behind a
 * fresh Microsoft confirmation. Only the person's own accounts, and a 404
 * for anything else, so the answer never reveals other links.
 */
class AccountPageTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private Company $derivados;

    private User $current;

    protected function setUp(): void
    {
        parent::setUp();

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);
        $this->derivados = Company::factory()->create(['name' => 'Soluciones y Derivados', 'short_name' => 'Derivados']);
        $this->current = User::factory()->microsoft($this->segurex)->create(['email' => 'edward@segurex.com']);
    }

    private function personWith(User ...$accounts): Person
    {
        $person = Person::query()->create();

        foreach ($accounts as $account) {
            $account->forceFill(['person_id' => $person->id])->save();
        }

        return $person;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function accountIn(Company $company, array $attributes = []): User
    {
        return User::factory()->microsoft($company)->create($attributes);
    }

    /**
     * Signed in as the current account, confirmed with Microsoft just now.
     */
    private function confirmed(?User $user = null): static
    {
        $user ??= $this->current;

        return $this->actingAs($user)->withSession([
            RecentMicrosoftLogin::SESSION_KEY => ['user_id' => $user->id, 'at' => now()->getTimestamp()],
        ]);
    }

    private function dom(TestResponse $response): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$response->getContent());

        return new DOMXPath($dom);
    }

    // --- The page ----------------------------------------------------------

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/cuenta')->assertRedirect(route('login'));
    }

    public function test_it_lists_the_accounts_of_the_person_only(): void
    {
        $derivados = $this->accountIn($this->derivados, ['email' => 'edward@derivados.com']);
        $this->personWith($this->current, $derivados);
        $stranger = $this->accountIn(Company::factory()->create(['name' => 'Andina Química']), ['email' => 'otra@andina.com']);
        $this->personWith($stranger, $this->accountIn(Company::factory()->create(['name' => 'Pacífico'])));
        $this->accountIn($this->derivados, ['email' => 'suelto@derivados.com']);

        $response = $this->actingAs($this->current)->get(route('account.show'))->assertOk();

        $response->assertSeeText('Segurex Latam')
            ->assertSeeText('edward@segurex.com')
            ->assertSeeText('Soluciones y Derivados')
            ->assertSeeText('edward@derivados.com')
            ->assertDontSeeText('otra@andina.com')
            ->assertDontSeeText('Andina Química')
            ->assertDontSeeText('Pacífico')
            ->assertDontSeeText('suelto@derivados.com');

        $rows = $this->dom($response)->query('//*[@data-linked-account]');
        $this->assertSame(2, $rows->length);
    }

    public function test_it_marks_the_current_account(): void
    {
        $this->personWith($this->current, $this->accountIn($this->derivados));

        $current = $this->dom($this->actingAs($this->current)->get(route('account.show')))
            ->query('//*[@data-linked-account][@aria-current="true"]');

        $this->assertSame(1, $current->length);
        $this->assertStringContainsString('edward@segurex.com', $current->item(0)->textContent);
        $this->assertStringContainsString('Esta cuenta', $current->item(0)->textContent);
    }

    public function test_each_account_offers_to_unlink_it(): void
    {
        $derivados = $this->accountIn($this->derivados);
        $this->personWith($this->current, $derivados);

        $links = $this->dom($this->actingAs($this->current)->get(route('account.show')))
            ->query('//*[@data-linked-account]//a[starts-with(normalize-space(.), "Desvincular")]');

        $this->assertSame(
            [route('account.unlink.confirm', $this->current), route('account.unlink.confirm', $derivados)],
            array_map(fn (DOMElement $link) => $link->getAttribute('href'), iterator_to_array($links, false)),
        );
        $this->assertStringContainsString('Soluciones y Derivados', $links->item(1)->textContent);
    }

    public function test_it_offers_to_link_another_company(): void
    {
        $this->personWith($this->current, $this->accountIn($this->derivados));

        $this->actingAs($this->current)->get(route('account.show'))
            ->assertSee('href="'.route('account.link').'"', false)
            ->assertSeeText('Vincular otra empresa');
    }

    public function test_a_person_without_links_gets_an_explanation_and_the_link_button(): void
    {
        $response = $this->actingAs($this->current)->get(route('account.show'))->assertOk();

        $response->assertSeeText('Aún no tienes cuentas vinculadas.')
            ->assertSee('href="'.route('account.link').'"', false)
            ->assertSeeText('Vincular otra empresa');
        $this->assertSame(0, $this->dom($response)->query('//*[@data-linked-account]')->length);
    }

    public function test_it_shows_the_status_message(): void
    {
        $this->actingAs($this->current)->withSession(['status' => 'Desvinculaste la cuenta de Soluciones y Derivados.'])
            ->get(route('account.show'))
            ->assertSee('role="status"', false)
            ->assertSeeText('Desvinculaste la cuenta de Soluciones y Derivados.');
    }

    public function test_emergency_accounts_get_a_404(): void
    {
        $this->actingAs(User::factory()->emergency()->create())->get('/cuenta')->assertNotFound();
    }

    public function test_the_account_menu_of_the_home_screen_links_to_the_page(): void
    {
        $links = $this->dom($this->actingAs($this->current)->get('/'))
            ->query('//details[@data-account]//a[@href="'.route('account.show').'"]');

        $this->assertSame(1, $links->length);
        $this->assertStringContainsString('Cuentas vinculadas', $links->item(0)->textContent);
    }

    public function test_emergency_accounts_get_no_link_to_the_page(): void
    {
        $this->actingAs(User::factory()->emergency()->create())->get('/')
            ->assertOk()
            ->assertDontSee('href="'.route('account.show').'"', false);
    }

    // --- The confirmation --------------------------------------------------

    public function test_the_confirmation_needs_a_recent_microsoft_confirmation(): void
    {
        $derivados = $this->accountIn($this->derivados);
        $this->personWith($this->current, $derivados);

        $this->actingAs($this->current)
            ->get(route('account.unlink.confirm', $derivados))
            ->assertRedirect(route('auth.microsoft.confirm', ['return' => '/cuenta/desvincular/'.$derivados->id]));
    }

    public function test_the_confirmation_names_the_account_and_posts_with_csrf(): void
    {
        $derivados = $this->accountIn($this->derivados, ['email' => 'edward@derivados.com']);
        $this->personWith($this->current, $derivados);

        $response = $this->confirmed()->get(route('account.unlink.confirm', $derivados))->assertOk()
            ->assertSeeText('Soluciones y Derivados')
            ->assertSeeText('edward@derivados.com');

        $form = $this->dom($response)->query('//form[@action="'.route('account.unlink', $derivados).'"]');
        $this->assertSame(1, $form->length);
        $this->assertSame('post', strtolower($form->item(0)->getAttribute('method')));
        $this->assertSame(1, $this->dom($response)->query('//form[@action="'.route('account.unlink', $derivados).'"]//input[@name="_token"]')->length);
        $this->assertNotNull($derivados->fresh()->person_id, 'Showing the confirmation unlinks nothing.');
    }

    public function test_the_confirmation_of_another_persons_account_is_a_404(): void
    {
        $this->personWith($this->current, $this->accountIn($this->derivados));
        $stranger = $this->accountIn(Company::factory()->create());
        $this->personWith($stranger, $this->accountIn(Company::factory()->create()));

        $this->confirmed()->get(route('account.unlink.confirm', $stranger))->assertNotFound();
    }

    public function test_the_confirmation_is_a_404_for_emergency_accounts(): void
    {
        $emergency = User::factory()->emergency()->create();

        $this->actingAs($emergency)->get(route('account.unlink.confirm', $emergency))->assertNotFound();
    }

    // --- Unlinking ---------------------------------------------------------

    public function test_unlinking_without_a_recent_confirmation_goes_to_the_confirmation(): void
    {
        $derivados = $this->accountIn($this->derivados);
        $this->personWith($this->current, $derivados);

        $this->actingAs($this->current)
            ->post(route('account.unlink', $derivados))
            ->assertRedirect(route('account.unlink.confirm', $derivados));

        $this->assertNotNull($derivados->fresh()->person_id);
        $this->assertSame(0, Activity::query()->where('event', SecurityLog::ACCOUNT_UNLINKED)->count());
    }

    public function test_a_confirmation_of_another_account_does_not_count(): void
    {
        $derivados = $this->accountIn($this->derivados);
        $this->personWith($this->current, $derivados);

        $this->actingAs($this->current)
            ->withSession([RecentMicrosoftLogin::SESSION_KEY => ['user_id' => $derivados->id, 'at' => now()->getTimestamp()]])
            ->post(route('account.unlink', $derivados))
            ->assertRedirect(route('account.unlink.confirm', $derivados));

        $this->assertNotNull($derivados->fresh()->person_id);
    }

    public function test_unlinking_another_persons_account_is_a_404(): void
    {
        $this->personWith($this->current, $this->accountIn($this->derivados));
        $stranger = $this->accountIn(Company::factory()->create());
        $strangersOther = $this->accountIn(Company::factory()->create());
        $person = $this->personWith($stranger, $strangersOther);

        $this->confirmed()->post(route('account.unlink', $stranger))->assertNotFound();

        $this->assertSame($person->id, $stranger->fresh()->person_id);
        $this->assertSame(0, Activity::query()->where('event', SecurityLog::ACCOUNT_UNLINKED)->count());
    }

    public function test_unlinking_an_account_that_is_not_linked_is_a_404(): void
    {
        $loose = $this->accountIn($this->derivados);

        $this->confirmed()->post(route('account.unlink', $loose))->assertNotFound();
        $this->confirmed()->post(route('account.unlink', $this->current))->assertNotFound();
        $this->confirmed()->post('/cuenta/desvincular/999999')->assertNotFound();
    }

    public function test_emergency_accounts_cannot_unlink(): void
    {
        $emergency = User::factory()->emergency()->create();

        $this->confirmed($emergency)->post(route('account.unlink', $emergency))->assertNotFound();
    }

    public function test_unlinking_needs_the_csrf_token(): void
    {
        $derivados = $this->accountIn($this->derivados);
        $this->personWith($this->current, $derivados);

        // The testing kernel skips CSRF; check it as a real request would.
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });

        $this->confirmed()->post(route('account.unlink', $derivados))->assertStatus(419);

        $this->assertNotNull($derivados->fresh()->person_id);
    }

    public function test_unlinking_one_of_three_accounts_keeps_the_person(): void
    {
        Notification::fake();
        $derivados = $this->accountIn($this->derivados, ['email' => 'edward@derivados.com']);
        $andina = $this->accountIn(Company::factory()->create(['name' => 'Andina Química']));
        $person = $this->personWith($this->current, $derivados, $andina);

        $this->confirmed()->post(route('account.unlink', $derivados))
            ->assertRedirect(route('account.show'))
            ->assertSessionHas('status', 'Desvinculaste la cuenta de Soluciones y Derivados.');

        $this->assertNull($derivados->fresh()->person_id);
        $this->assertSame($person->id, $this->current->fresh()->person_id);
        $this->assertSame($person->id, $andina->fresh()->person_id);
        $this->assertAuthenticatedAs($this->current);

        $entry = Activity::query()->where('event', SecurityLog::ACCOUNT_UNLINKED)->sole();
        $this->assertSame(LinkedAccounts::USER, $entry->properties['reason']);
        $this->assertTrue($entry->causer->is($this->current));
        $this->assertTrue($entry->subject->is($derivados));
        $this->assertSame(
            'Desvinculó la cuenta de Soluciones y Derivados (edward@derivados.com) · por la misma persona',
            $this->app->make(AuditPresenter::class)->activity($entry)->what,
        );
    }

    public function test_unlinking_deletes_a_person_left_with_one_account(): void
    {
        Notification::fake();
        $derivados = $this->accountIn($this->derivados);
        $person = $this->personWith($this->current, $derivados);

        $this->confirmed()->post(route('account.unlink', $derivados))->assertRedirect(route('account.show'));

        $this->assertModelMissing($person);
        $this->assertNull($this->current->fresh()->person_id);
        $this->assertNull($derivados->fresh()->person_id);
        $this->assertEqualsCanonicalizing(
            [$this->current->id, $derivados->id],
            Activity::query()->where('event', SecurityLog::ACCOUNT_UNLINKED)->pluck('subject_id')->all(),
        );
    }

    public function test_the_current_account_can_unlink_itself(): void
    {
        Notification::fake();
        $derivados = $this->accountIn($this->derivados);
        $andina = $this->accountIn(Company::factory()->create());
        $person = $this->personWith($this->current, $derivados, $andina);

        $this->confirmed()->post(route('account.unlink', $this->current))
            ->assertRedirect(route('account.show'))
            ->assertSessionHas('status', 'Desvinculaste la cuenta de Segurex Latam.');

        $this->assertNull($this->current->fresh()->person_id);
        $this->assertSame($person->id, $derivados->fresh()->person_id);
        $this->assertSame($person->id, $andina->fresh()->person_id);
        $this->assertAuthenticatedAs($this->current);
    }

    public function test_every_account_of_the_person_gets_the_notice(): void
    {
        Notification::fake();
        $derivados = $this->accountIn($this->derivados, ['email' => 'edward@derivados.com']);
        $andina = $this->accountIn(Company::factory()->create());
        $this->personWith($this->current, $derivados, $andina);

        $this->confirmed()->post(route('account.unlink', $derivados));

        Notification::assertSentTo([$this->current, $derivados, $andina], LinkedAccountNotice::class);
        Notification::assertSentTimes(LinkedAccountNotice::class, 3);
        Notification::assertSentTo(
            $andina,
            LinkedAccountNotice::class,
            fn (LinkedAccountNotice $notice) => $notice->kind === LinkedAccountNotice::UNLINKED
                && $notice->firstCompany === 'Soluciones y Derivados'
                && $notice->firstEmail === 'edward@derivados.com',
        );
    }

    public function test_the_unlink_notice_names_the_account_and_who_did_it(): void
    {
        $this->freezeTime();
        $derivados = $this->accountIn($this->derivados, ['email' => 'edward@derivados.com']);

        $mail = LinkedAccountNotice::unlinked($derivados, LinkedAccounts::USER)->toMail($this->current);
        $html = (string) $mail->render();

        $this->assertSame('Se desvinculó una cuenta tuya del portal', $mail->subject);
        $this->assertStringContainsString('Soluciones y Derivados: edward@derivados.com', $html);
        $this->assertStringContainsString('Lo hiciste tú desde el portal.', $html);
        $this->assertStringContainsString(now()->timezone(config('app.timezone'))->locale('es')->isoFormat('D [de] MMMM [de] YYYY'), $html);
        $this->assertStringContainsString('Si no fuiste tú, avisa a TI.', $html);

        $byAdmin = (string) LinkedAccountNotice::unlinked($derivados, LinkedAccounts::ADMIN)->toMail($this->current)->render();
        $this->assertStringContainsString('Lo hizo un administrador del portal.', $byAdmin);
    }

    public function test_a_mail_failure_does_not_block_the_unlink(): void
    {
        $warnings = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$warnings) {
            if ($event->level === 'warning') {
                $warnings[] = $event->message;
            }
        });
        Notification::shouldReceive('sendNow')->andThrow(new RuntimeException('SMTP down'));
        $derivados = $this->accountIn($this->derivados);
        $this->personWith($this->current, $derivados);

        $this->confirmed()->post(route('account.unlink', $derivados))->assertRedirect(route('account.show'));

        $this->assertNull($derivados->fresh()->person_id);
        $this->assertContains('Linked account notice could not be sent.', $warnings);
    }

    public function test_an_automatic_unlink_sends_no_notice(): void
    {
        Notification::fake();
        $derivados = $this->accountIn($this->derivados);
        $this->personWith($this->current, $derivados);

        $this->app->make(LinkedAccounts::class)->unlink($derivados, LinkedAccounts::COMPANY_CHANGED);

        Notification::assertNothingSent();
    }
}
