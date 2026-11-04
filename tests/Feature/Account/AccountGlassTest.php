<?php

namespace Tests\Feature\Account;

use App\Auth\RecentMicrosoftLogin;
use App\Models\Company;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Layouts\InspectsGlassPages;
use Tests\Feature\Push\PushTestKeys;
use Tests\TestCase;

/**
 * /cuenta, /cuenta/vincular and the unlink confirmation in Liquid Glass
 * (odd/tasks/liquid-glass.md G9): a title banner and glass cards, controls
 * from the kit (g-btn, g-select), the current account as a g-pill, and no
 * «empresa del grupo» wording (Jhon, 2026-11-04). Routes, forms and data
 * are AccountPageTest's, BirthdayTest's and LinkAccountFlowTest's.
 */
class AccountGlassTest extends TestCase
{
    use InspectsGlassPages, PushTestKeys, RefreshDatabase;

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

    public function test_the_account_page_is_a_glass_page_with_a_card_per_section(): void
    {
        $this->configurePush();
        $this->link(User::factory()->microsoft($this->derivados)->create());

        $page = $this->xpathOf($this->actingAs($this->current)->get(route('account.show'))->assertOk());

        $this->assertGlassPage($page, '/cuenta');
        foreach (['tus-cuentas', 'cumpleanos-titulo', 'avisos-titulo'] as $heading) {
            $this->assertSame(1, $page->query('//main//section['.self::hasClass('g-card').'][@aria-labelledby="'.$heading.'"]//*['.self::hasClass('g-card__head').']/h2[@id="'.$heading.'"]')->length, $heading);
        }
    }

    public function test_the_current_account_is_marked_with_a_pill(): void
    {
        $this->link(User::factory()->microsoft($this->derivados)->create());

        $page = $this->xpathOf($this->actingAs($this->current)->get(route('account.show'))->assertOk());

        $pills = $page->query('//*[@data-linked-account][@aria-current="true"]//*['.self::hasClass('g-pill').']');
        $this->assertSame(1, $pills->length);
        $this->assertSame('Esta cuenta', trim($pills->item(0)->textContent));
        $this->assertSame(0, $page->query('//*[@data-linked-account][not(@aria-current)]//*['.self::hasClass('g-pill').']')->length);
    }

    public function test_the_account_actions_use_the_glass_buttons(): void
    {
        $this->link(User::factory()->microsoft($this->derivados)->create());
        $this->current->forceFill(['birthday_month' => 3, 'birthday_day' => 5])->save();

        $page = $this->xpathOf($this->actingAs($this->current)->get(route('account.show'))->assertOk());

        $this->assertSame(1, $page->query('//main//a[@href="'.route('account.link').'"]['.self::hasClass('g-btn').']['.self::hasClass('g-btn--primary').']')->length);
        $this->assertSame(2, $page->query('//*[@data-linked-account]//a['.self::hasClass('g-btn').']['.self::hasClass('g-btn--danger').']')->length);
        $this->assertSame(1, $page->query('//form[@action="'.route('account.birthday.update').'"][@method="POST"]//button[@type="submit"]['.self::hasClass('g-btn--primary').']')->length);
        $this->assertSame(1, $page->query('//form[@action="'.route('account.birthday.destroy').'"][@method="POST"][.//input[@name="_method"][@value="DELETE"]][.//input[@name="_token"]]//button['.self::hasClass('g-btn--danger').']')->length);
        $this->assertSame(2, $page->query('//section[@id="cumpleanos"]//select['.self::hasClass('g-select').']')->length);
    }

    public function test_the_pages_never_call_the_companies_a_group(): void
    {
        $derivados = User::factory()->microsoft($this->derivados)->create();

        // Without links: the explanation that invites to link another company.
        $empty = $this->xpathOf($this->actingAs($this->current)->get(route('account.show'))->assertOk());
        $this->assertStringContainsString('en otra empresa', $this->mainText($empty));
        $this->assertStringNotContainsStringIgnoringCase('grupo', $this->mainText($empty));

        $this->link($derivados);

        $pages = [
            '/cuenta' => route('account.show'),
            '/cuenta/vincular' => route('account.link'),
            'confirmation' => route('account.unlink.confirm', $derivados),
        ];
        foreach ($pages as $label => $url) {
            $page = $this->xpathOf($this->confirmed()->get($url)->assertOk());

            $this->assertGlassPage($page, $label);
            $this->assertStringNotContainsStringIgnoringCase('grupo', $this->mainText($page), $label);
        }
    }

    public function test_the_link_page_keeps_its_form_in_a_glass_card(): void
    {
        $page = $this->xpathOf($this->confirmed()->get(route('account.link'))->assertOk());

        $this->assertStringContainsString('Si también tienes una cuenta de Microsoft en otra empresa, vincúlala con esta', $this->mainText($page));
        $form = $page->query('//main//*['.self::hasClass('g-card').']//form[@action="'.route('account.link.start').'"][@method="POST"]');
        $this->assertSame(1, $form->length);
        $this->assertSame(1, $page->query('.//input[@name="_token"]', $form->item(0))->length);
        $this->assertSame(1, $page->query('.//button[@type="submit"]['.self::hasClass('g-btn--primary').']', $form->item(0))->length);
    }

    public function test_the_confirmation_offers_a_danger_button_and_a_way_back(): void
    {
        $derivados = User::factory()->microsoft($this->derivados)->create();
        $this->link($derivados);

        $page = $this->xpathOf($this->confirmed()->get(route('account.unlink.confirm', $derivados))->assertOk());

        $this->assertSame(1, $page->query('//form[@action="'.route('account.unlink', $derivados).'"][@method="POST"][.//input[@name="_token"]]//button[@type="submit"]['.self::hasClass('g-btn--danger').']')->length);
        $this->assertSame(1, $page->query('//main//a[@href="'.route('account.show').'"]['.self::hasClass('g-btn').'][normalize-space(.)="Cancelar"]')->length);
    }

    private function link(User ...$others): void
    {
        $person = Person::query()->create();

        foreach ([$this->current, ...$others] as $account) {
            $account->forceFill(['person_id' => $person->id])->save();
        }
    }

    /**
     * Signed in as the current account, confirmed with Microsoft just now
     * (the link page and the unlink confirmation need it).
     */
    private function confirmed(): static
    {
        return $this->actingAs($this->current)->withSession([
            RecentMicrosoftLogin::SESSION_KEY => ['user_id' => $this->current->id, 'at' => now()->getTimestamp()],
        ]);
    }
}
