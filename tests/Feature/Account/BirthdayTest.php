<?php

namespace Tests\Feature\Account;

use App\Models\Company;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * «Mi cumpleaños» on /cuenta (odd/tasks/personas.md WU2): each person
 * writes only the day and month of their birthday and chooses whether the
 * people of their company see it (off by default), and can remove it. Only
 * for accounts with a company directory (App\Models\DirectoryEntry::companyIdFor()).
 */
class BirthdayTest extends TestCase
{
    use RefreshDatabase;

    /** «Quitar mi cumpleaños»: the same URL as saving, with DELETE. */
    private const REMOVE_FORM = '//section[@id="cumpleanos"]//form[.//input[@name="_method"][@value="DELETE"]]';

    private Company $segurex;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);
        $this->ana = User::factory()->microsoft($this->segurex)->create(['name' => 'Ana María Pérez']);
    }

    public function test_the_columns_exist_and_the_birthday_is_hidden_by_default(): void
    {
        $this->assertTrue(Schema::hasColumns('users', ['birthday_month', 'birthday_day', 'show_birthday']));
        $this->assertFalse(Schema::hasColumn('users', 'birthday_year'));

        $fresh = $this->ana->fresh();
        $this->assertNull($fresh->birthday_month);
        $this->assertNull($fresh->birthday_day);
        $this->assertFalse($fresh->show_birthday);
    }

    public function test_the_birthday_is_not_mass_assignable(): void
    {
        $user = new User;
        $user->fill(['birthday_month' => 3, 'birthday_day' => 5, 'show_birthday' => true]);

        $this->assertNull($user->birthday_month);
        $this->assertNull($user->birthday_day);
        $this->assertNull($user->getAttributes()['show_birthday'] ?? null);
    }

    public function test_the_page_has_the_section_with_its_explanation(): void
    {
        $response = $this->actingAs($this->ana)->get('/cuenta')->assertOk();
        $xpath = $this->xpath($response);

        $section = $xpath->query('//section[@id="cumpleanos"]');
        $this->assertSame(1, $section->length);
        $text = preg_replace('/\s+/u', ' ', $section->item(0)->textContent);
        $this->assertStringContainsString('Mi cumpleaños', $text);
        $this->assertStringContainsString('nunca el año', $text);
        $this->assertStringContainsString('Solo lo ven las personas de tu empresa', $text);
        $this->assertStringContainsString('cuando quieras', $text);

        $form = $xpath->query('//section[@id="cumpleanos"]//form[@action="'.route('account.birthday.update').'"][@method="POST"]');
        $this->assertSame(1, $form->length);
        $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $form->item(0))->length);

        $days = $xpath->query('.//select[@name="birthday_day"]/option[@value!=""]', $form->item(0));
        $this->assertSame(31, $days->length);
        $months = $xpath->query('.//select[@name="birthday_month"]/option[@value!=""]', $form->item(0));
        $this->assertSame(12, $months->length);
        $this->assertSame('Enero', trim($months->item(0)->textContent));
        $this->assertSame('Febrero', trim($months->item(1)->textContent));
        $this->assertSame('Diciembre', trim($months->item(11)->textContent));
        // No year anywhere.
        $this->assertSame(0, $xpath->query('.//*[@name="birthday_year"]', $form->item(0))->length);

        $checkbox = $xpath->query('.//input[@type="checkbox"][@name="show_birthday"]', $form->item(0));
        $this->assertSame(1, $checkbox->length);
        $this->assertFalse($checkbox->item(0)->hasAttribute('checked'), 'The box is unchecked by default.');
        $this->assertStringContainsString('Mostrar mi cumpleaños a las personas de mi empresa', preg_replace('/\s+/u', ' ', $form->item(0)->textContent));
        $this->assertStringContainsString('Guardar', $form->item(0)->textContent);

        // Nothing to remove yet.
        $this->assertSame(0, $xpath->query(self::REMOVE_FORM)->length);
    }

    public function test_saving_keeps_the_day_the_month_and_the_choice(): void
    {
        $this->actingAs($this->ana)
            ->post(route('account.birthday.update'), ['birthday_day' => '5', 'birthday_month' => '3', 'show_birthday' => '1'])
            ->assertRedirect(route('account.show'))
            ->assertSessionHas('status', 'Guardamos tu cumpleaños.');

        $fresh = $this->ana->fresh();
        $this->assertSame(3, $fresh->birthday_month);
        $this->assertSame(5, $fresh->birthday_day);
        $this->assertTrue($fresh->show_birthday);

        $response = $this->actingAs($fresh)->get('/cuenta')->assertOk()->assertSeeText('Guardamos tu cumpleaños.');
        $xpath = $this->xpath($response);
        $this->assertSame('5', $xpath->query('//select[@name="birthday_day"]/option[@selected]')->item(0)->getAttribute('value'));
        $this->assertSame('3', $xpath->query('//select[@name="birthday_month"]/option[@selected]')->item(0)->getAttribute('value'));
        $this->assertTrue($xpath->query('//input[@name="show_birthday"]')->item(0)->hasAttribute('checked'));
        $this->assertSame(1, $xpath->query(self::REMOVE_FORM.'//button')->length);
        // Neutral: the account may not be in the directory (yet).
        $response->assertSeeText('Tu cumpleaños: 5 de marzo. Se muestra en «Mi día» a las personas de tu empresa.');
        $response->assertDontSeeText('Lo ven las personas de tu empresa');
    }

    public function test_without_the_box_the_birthday_is_kept_but_not_shown(): void
    {
        $this->actingAs($this->ana)
            ->post(route('account.birthday.update'), ['birthday_day' => '12', 'birthday_month' => '10'])
            ->assertRedirect(route('account.show'));

        $fresh = $this->ana->fresh();
        $this->assertSame(10, $fresh->birthday_month);
        $this->assertSame(12, $fresh->birthday_day);
        $this->assertFalse($fresh->show_birthday);

        // Unchecking it later hides it again.
        $fresh->forceFill(['show_birthday' => true])->save();
        $this->actingAs($fresh)->post(route('account.birthday.update'), ['birthday_day' => '12', 'birthday_month' => '10']);
        $this->assertFalse($this->ana->fresh()->show_birthday);
    }

    public function test_29_february_is_accepted(): void
    {
        $this->actingAs($this->ana)
            ->post(route('account.birthday.update'), ['birthday_day' => '29', 'birthday_month' => '2', 'show_birthday' => '1'])
            ->assertRedirect(route('account.show'))
            ->assertSessionHasNoErrors();

        $this->assertSame([2, 29], [$this->ana->fresh()->birthday_month, $this->ana->fresh()->birthday_day]);
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function invalidBirthdays(): array
    {
        return [
            '31 April' => [['birthday_day' => '31', 'birthday_month' => '4'], 'birthday_day'],
            '30 February' => [['birthday_day' => '30', 'birthday_month' => '2'], 'birthday_day'],
            'month 13' => [['birthday_day' => '1', 'birthday_month' => '13'], 'birthday_month'],
            'month 0' => [['birthday_day' => '1', 'birthday_month' => '0'], 'birthday_month'],
            'day 0' => [['birthday_day' => '0', 'birthday_month' => '1'], 'birthday_day'],
            'day 32' => [['birthday_day' => '32', 'birthday_month' => '1'], 'birthday_day'],
            'no day' => [['birthday_month' => '1'], 'birthday_day'],
            'no month' => [['birthday_day' => '1'], 'birthday_month'],
            'text' => [['birthday_day' => 'cinco', 'birthday_month' => 'marzo'], 'birthday_day'],
            'a fraction' => [['birthday_day' => '1.5', 'birthday_month' => '3'], 'birthday_day'],
            'an array' => [['birthday_day' => ['1'], 'birthday_month' => '3'], 'birthday_day'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    #[DataProvider('invalidBirthdays')]
    public function test_an_impossible_date_is_refused(array $input, string $field): void
    {
        $this->ana->forceFill(['birthday_month' => 7, 'birthday_day' => 20, 'show_birthday' => true])->save();

        $this->actingAs($this->ana)
            ->from(route('account.show'))
            ->post(route('account.birthday.update'), $input)
            ->assertRedirect(route('account.show').'#cumpleanos')
            ->assertSessionHasErrors($field);

        $fresh = $this->ana->fresh();
        $this->assertSame([7, 20, true], [$fresh->birthday_month, $fresh->birthday_day, $fresh->show_birthday]);
    }

    public function test_the_error_is_in_spanish_on_the_page(): void
    {
        $this->actingAs($this->ana)
            ->from(route('account.show'))
            ->post(route('account.birthday.update'), ['birthday_day' => '31', 'birthday_month' => '4']);

        $this->actingAs($this->ana)->get('/cuenta')
            ->assertOk()
            ->assertSeeText('Ese día no existe en el mes que elegiste.');
    }

    public function test_removing_clears_the_day_the_month_and_the_choice(): void
    {
        $this->ana->forceFill(['birthday_month' => 3, 'birthday_day' => 5, 'show_birthday' => true])->save();

        $this->actingAs($this->ana)
            ->delete(route('account.birthday.destroy'))
            ->assertRedirect(route('account.show'))
            ->assertSessionHas('status', 'Quitamos tu cumpleaños.');

        $fresh = $this->ana->fresh();
        $this->assertNull($fresh->birthday_month);
        $this->assertNull($fresh->birthday_day);
        $this->assertFalse($fresh->show_birthday);
    }

    public function test_saving_and_removing_need_the_csrf_token(): void
    {
        // The testing kernel skips CSRF; check it as a real request would.
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });

        $this->actingAs($this->ana)
            ->post(route('account.birthday.update'), ['birthday_day' => '5', 'birthday_month' => '3'])
            ->assertStatus(419);
        $this->actingAs($this->ana)->delete(route('account.birthday.destroy'))->assertStatus(419);

        $this->assertNull($this->ana->fresh()->birthday_month);
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->post(route('account.birthday.update'), ['birthday_day' => '5', 'birthday_month' => '3'])->assertRedirect(route('login'));
        $this->delete(route('account.birthday.destroy'))->assertRedirect(route('login'));
    }

    public function test_emergency_accounts_cannot_use_it(): void
    {
        $emergency = User::factory()->emergency()->create();

        $this->actingAs($emergency)->post(route('account.birthday.update'), ['birthday_day' => '5', 'birthday_month' => '3'])->assertNotFound();
        $this->actingAs($emergency)->delete(route('account.birthday.destroy'))->assertNotFound();

        $this->assertNull($emergency->fresh()->birthday_month);
    }

    /**
     * @return array<string, array{0: \Closure(Company): User}>
     */
    public static function accountsWithoutDirectory(): array
    {
        return [
            'a guest' => [fn (Company $company) => User::factory()->microsoft($company)->create(['is_guest' => true])],
            'an inactive company' => [fn (Company $company) => User::factory()->microsoft(Company::factory()->inactive()->create())->create()],
            'no company' => [fn (Company $company) => User::factory()->microsoft($company)->create(['company_id' => null])],
        ];
    }

    /**
     * @param  \Closure(Company): User  $make
     */
    #[DataProvider('accountsWithoutDirectory')]
    public function test_accounts_without_a_directory_neither_see_nor_save_it(\Closure $make): void
    {
        $user = $make($this->segurex);

        $this->actingAs($user)->get('/cuenta')->assertOk()->assertDontSee('id="cumpleanos"', false)->assertDontSeeText('Mi cumpleaños');
        $this->actingAs($user)->post(route('account.birthday.update'), ['birthday_day' => '5', 'birthday_month' => '3'])->assertNotFound();

        $this->assertNull($user->fresh()->birthday_month);
    }

    /**
     * Removing is the person's right (Ley 1581) whatever happened to the
     * account since: it only clears the caller's own birthday.
     *
     * @param  \Closure(Company): User  $make
     */
    #[DataProvider('accountsWithoutDirectory')]
    public function test_a_stored_birthday_can_still_be_removed_without_a_directory(\Closure $make): void
    {
        $user = $make($this->segurex);
        $user->forceFill(['birthday_month' => 3, 'birthday_day' => 5, 'show_birthday' => true])->save();

        // Only «Quitar»: saving stays for accounts with a directory.
        $xpath = $this->xpath($this->actingAs($user)->get('/cuenta')->assertOk());
        $this->assertSame(1, $xpath->query('//section[@id="cumpleanos"]')->length);
        $this->assertSame(1, $xpath->query(self::REMOVE_FORM.'//button')->length);
        $this->assertSame(0, $xpath->query('//section[@id="cumpleanos"]//select')->length);
        $this->assertSame(0, $xpath->query('//section[@id="cumpleanos"]//input[@name="show_birthday"]')->length);

        $this->actingAs($user)
            ->delete(route('account.birthday.destroy'))
            ->assertRedirect(route('account.show'))
            ->assertSessionHas('status', 'Quitamos tu cumpleaños.');

        $fresh = $user->fresh();
        $this->assertSame([null, null, false], [$fresh->birthday_month, $fresh->birthday_day, $fresh->show_birthday]);
        $this->actingAs($fresh)->get('/cuenta')->assertOk()->assertDontSee('id="cumpleanos"', false);
    }

    public function test_a_deactivated_company_does_not_keep_the_birthday_from_being_removed(): void
    {
        $this->ana->forceFill(['birthday_month' => 3, 'birthday_day' => 5, 'show_birthday' => true])->save();
        $this->segurex->forceFill(['is_active' => false])->save();

        $this->actingAs($this->ana->fresh())
            ->delete(route('account.birthday.destroy'))
            ->assertRedirect(route('account.show'));

        $fresh = $this->ana->fresh();
        $this->assertSame([null, null, false], [$fresh->birthday_month, $fresh->birthday_day, $fresh->show_birthday]);
    }

    public function test_removing_touches_only_the_callers_own_birthday(): void
    {
        $luis = User::factory()->microsoft($this->segurex)->birthday(7, 20)->create();
        $this->ana->forceFill(['birthday_month' => 3, 'birthday_day' => 5, 'show_birthday' => true])->save();

        $this->actingAs($this->ana)->delete(route('account.birthday.destroy'), ['user' => $luis->id, 'user_id' => $luis->id]);

        $this->assertNull($this->ana->fresh()->birthday_month);
        $this->assertSame([7, 20, true], [$luis->fresh()->birthday_month, $luis->fresh()->birthday_day, $luis->fresh()->show_birthday]);
    }

    public function test_the_routes_are_throttled(): void
    {
        foreach (['account.birthday.update', 'account.birthday.destroy'] as $name) {
            $this->assertContains('throttle:20,1', app('router')->getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
    }

    public function test_the_account_menu_of_the_home_screen_links_to_the_section(): void
    {
        $xpath = $this->xpath($this->actingAs($this->ana)->get('/')->assertOk());

        $links = $xpath->query('//details[@data-account]//a[@href="'.route('account.show').'#cumpleanos"]');
        $this->assertSame(1, $links->length);
        $this->assertStringContainsString('Mi cumpleaños', $links->item(0)->textContent);

        $guest = User::factory()->microsoft($this->segurex)->create(['is_guest' => true]);
        $this->actingAs($guest)->get('/')->assertOk()->assertDontSee('#cumpleanos', false);
    }

    private function xpath(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8"?>'.$response->getContent());

        return new DOMXPath($document);
    }
}
