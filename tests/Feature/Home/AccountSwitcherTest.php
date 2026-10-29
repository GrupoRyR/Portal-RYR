<?php

namespace Tests\Feature\Home;

use App\Models\Company;
use App\Models\Person;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The company switcher in the header of the home screen and of the
 * secondary pages (components/brand-bar): the person's other accounts
 * they may switch to (App\Auth\LinkedAccounts::canSwitch()), each a POST
 * to `account.switch`. Inline buttons up to three companies, a
 * <details> menu from four, no JavaScript.
 */
class AccountSwitcherTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private Company $derivados;

    private User $current;

    protected function setUp(): void
    {
        parent::setUp();

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam S.A.S.', 'short_name' => 'Segurex']);
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

    private function page(string $path = '/', ?User $user = null): DOMXPath
    {
        $html = $this->actingAs($user ?? $this->current->fresh())->get($path)->assertOk()->getContent();

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);

        return new DOMXPath($dom);
    }

    private function switcher(DOMXPath $page): ?DOMElement
    {
        $node = $page->query('//*[@data-account-switcher]')->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    /**
     * @return list<DOMElement>
     */
    private function switchForms(DOMXPath $page): array
    {
        return iterator_to_array($page->query('//*[@data-account-switcher]//form'), false);
    }

    /**
     * @return list<string>
     */
    private function buttonNames(DOMXPath $page): array
    {
        return array_map(
            fn (DOMElement $button) => trim(preg_replace('/\s+/', ' ', $button->textContent)),
            iterator_to_array($page->query('//*[@data-account-switcher]//form//button'), false),
        );
    }

    public function test_it_offers_the_other_linked_account_on_the_home_screen(): void
    {
        $derivados = $this->accountIn($this->derivados);
        $this->personWith($this->current, $derivados);

        $page = $this->page();

        $this->assertNotNull($this->switcher($page));
        $this->assertSame(['Cambiar a Derivados'], $this->buttonNames($page));
    }

    public function test_every_switch_button_posts_to_the_switch_route_with_csrf(): void
    {
        $derivados = $this->accountIn($this->derivados);
        $andina = $this->accountIn(Company::factory()->create(['short_name' => 'Andina']));
        $this->personWith($this->current, $derivados, $andina);

        $forms = $this->switchForms($this->page());

        $this->assertCount(2, $forms);
        $this->assertSame(
            [route('account.switch', $derivados), route('account.switch', $andina)],
            array_map(fn (DOMElement $form) => $form->getAttribute('action'), $forms),
        );

        foreach ($forms as $form) {
            $this->assertSame('post', strtolower($form->getAttribute('method')));
            $token = (new DOMXPath($form->ownerDocument))->query('.//input[@name="_token"]', $form)->item(0);
            $this->assertInstanceOf(DOMElement::class, $token);
            $this->assertNotSame('', $token->getAttribute('value'));
        }
    }

    public function test_the_current_company_is_marked_and_is_not_a_button(): void
    {
        $this->personWith($this->current, $this->accountIn($this->derivados));

        $page = $this->page();
        $current = $page->query('//*[@data-account-switcher]//*[@aria-current]');

        $this->assertSame(1, $current->length);
        $this->assertStringContainsString('Segurex', $current->item(0)->textContent);
        $this->assertNotSame('button', $current->item(0)->nodeName);
        $this->assertSame(0, $page->query('//*[@data-account-switcher]//*[@aria-current]/ancestor-or-self::button')->length);
        $this->assertNotContains('Cambiar a Segurex', $this->buttonNames($page));
    }

    public function test_it_shows_only_accounts_of_active_companies_that_may_be_switched_to(): void
    {
        $this->personWith(
            $this->current,
            $this->accountIn($this->derivados),
            $this->accountIn(Company::factory()->inactive()->create(['short_name' => 'Inactiva'])),
            $this->accountIn(Company::factory()->create(['short_name' => 'Invitada']), ['is_guest' => true]),
        );
        // Not known to be a home account yet: refused like a guest.
        $unknown = $this->accountIn(Company::factory()->create(['short_name' => 'Desconocida']));
        $unknown->forceFill(['person_id' => $this->current->fresh()->person_id, 'is_guest' => null])->save();

        $page = $this->page();

        $this->assertSame(['Cambiar a Derivados'], $this->buttonNames($page));
        $switcher = $this->switcher($page)->textContent;
        $this->assertStringNotContainsString('Inactiva', $switcher);
        $this->assertStringNotContainsString('Invitada', $switcher);
        $this->assertStringNotContainsString('Desconocida', $switcher);
    }

    public function test_it_is_hidden_without_linked_accounts(): void
    {
        $this->assertNull($this->switcher($this->page()));
    }

    public function test_it_is_hidden_when_no_other_account_may_be_switched_to(): void
    {
        $this->personWith($this->current, $this->accountIn(Company::factory()->inactive()->create()));

        $this->assertNull($this->switcher($this->page()));
    }

    public function test_it_is_hidden_for_emergency_accounts(): void
    {
        $emergency = User::factory()->emergency()->create();

        $this->assertNull($this->switcher($this->page('/', $emergency)));
    }

    public function test_up_to_three_companies_are_inline_buttons(): void
    {
        $this->personWith(
            $this->current,
            $this->accountIn($this->derivados),
            $this->accountIn(Company::factory()->create(['short_name' => 'Andina'])),
        );

        $page = $this->page();

        $this->assertSame(0, $page->query('//details[@data-account-switcher]')->length);
        $this->assertSame(0, $page->query('//*[@data-account-switcher]//details')->length);
        $this->assertSame(['Cambiar a Derivados', 'Cambiar a Andina'], $this->buttonNames($page));
    }

    public function test_four_companies_or_more_are_a_menu_without_javascript(): void
    {
        $this->personWith(
            $this->current,
            $this->accountIn($this->derivados),
            $this->accountIn(Company::factory()->create(['short_name' => 'Andina'])),
            $this->accountIn(Company::factory()->create(['short_name' => 'Pacífico'])),
        );

        $page = $this->page();

        $menu = $page->query('//details[@data-account-switcher]');
        $this->assertSame(1, $menu->length);
        $this->assertSame(1, $page->query('//details[@data-account-switcher]/summary')->length);
        $this->assertStringContainsString('Segurex', $page->query('//details[@data-account-switcher]/summary')->item(0)->textContent);
        $this->assertSame(['Cambiar a Derivados', 'Cambiar a Andina', 'Cambiar a Pacífico'], $this->buttonNames($page));
    }

    public function test_the_secondary_pages_show_the_switcher_in_their_band(): void
    {
        $derivados = $this->accountIn($this->derivados);
        $this->personWith($this->current, $derivados);

        $page = $this->page(route('announcements.index', absolute: false));

        $this->assertNotNull($this->switcher($page));
        $this->assertSame(['Cambiar a Derivados'], $this->buttonNames($page));
        $this->assertSame(route('account.switch', $derivados), $this->switchForms($page)[0]->getAttribute('action'));
    }

    public function test_the_secondary_pages_hide_it_without_linked_accounts(): void
    {
        $this->assertNull($this->switcher($this->page(route('announcements.index', absolute: false))));
    }

    public function test_a_company_with_an_empty_short_name_shows_its_name(): void
    {
        $this->personWith($this->current, $this->accountIn(Company::factory()->create(['name' => 'Andina de Cerraduras', 'short_name' => ''])));

        $this->assertSame(['Cambiar a Andina de Cerraduras'], $this->buttonNames($this->page()));
    }

    // --- Status message ----------------------------------------------------

    public function test_the_status_message_shows_on_the_home_screen(): void
    {
        $html = $this->actingAs($this->current)
            ->withSession(['status' => 'Ahora estás en tu cuenta de Segurex Latam S.A.S..'])
            ->get('/')
            ->assertOk()
            ->getContent();

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $status = (new DOMXPath($dom))->query('//*[@data-flash][@role="status"]');

        $this->assertSame(1, $status->length);
        $this->assertSame('Ahora estás en tu cuenta de Segurex Latam S.A.S..', trim($status->item(0)->textContent));
    }

    public function test_the_home_screen_has_no_status_message_by_default(): void
    {
        $this->assertSame(0, $this->page()->query('//*[@data-flash]')->length);
    }
}
