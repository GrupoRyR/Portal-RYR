<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\AppAccessRules;
use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AppAccessRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $segurex;

    private Company $derivados;

    private App $cartera;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);
        $this->derivados = Company::factory()->create(['name' => 'Soluciones y Derivados', 'short_name' => 'Derivados']);
        $this->cartera = App::factory()->forCompanies($this->segurex)->create(['name' => 'Cartera', 'slug' => 'cartera']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('portal_admin');
    }

    public function test_the_editor_page_shows_the_rules_and_the_hint(): void
    {
        AppAccessRule::forGroup($this->cartera, $this->segurex, 'APP-Cartera');

        $this->actingAs($this->admin)->get(route('admin.apps.edit', $this->cartera))
            ->assertOk()
            ->assertSee('Quién la ve')
            ->assertSee('Basta con cumplir una regla. La app también debe validar el acceso por su cuenta.')
            ->assertSeeInOrder(['APP-Cartera', 'Grupo de Microsoft · Segurex'])
            ->assertSee('aria-label="Quitar regla APP-Cartera"', false);
    }

    public function test_users_without_an_admin_role_cannot_use_the_component(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(AppAccessRules::class, ['app' => $this->cartera])
            ->assertForbidden();
    }

    public function test_a_group_rule_is_added_with_suggestions_from_the_company(): void
    {
        $this->member($this->segurex, ['APP-Cartera', 'Ventas Bogotá']);
        $this->member($this->segurex, ['APP-Camaras']);
        $this->member($this->derivados, ['APP-Portafolio']);

        $component = $this->rules()
            ->call('open', 'group')
            ->set('companyId', (string) $this->segurex->id)
            ->assertSee('<option value="APP-Camaras">', false)
            ->assertSee('<option value="APP-Cartera">', false)
            ->assertDontSee('APP-Portafolio')
            ->assertDontSee('Ventas Bogotá');

        $component->set('groupName', '  APP-Cartera ')->call('addGroup')->assertHasNoErrors();

        $rule = $this->cartera->accessRules()->sole();
        $this->assertSame([AppAccessRule::TYPE_GROUP, 'APP-Cartera', $this->segurex->id], [$rule->type, $rule->value, $rule->company_id]);

        // Free text is allowed: the group may not have members yet.
        $component->call('open', 'group')->set('groupName', 'APP-CarteraNueva')->call('addGroup')->assertHasNoErrors();
        $this->assertSame(2, $this->cartera->accessRules()->count());
    }

    public function test_duplicate_group_rules_are_rejected_whatever_the_case(): void
    {
        AppAccessRule::forGroup($this->cartera, $this->segurex, 'APP-Cartera');

        $this->rules()
            ->call('open', 'group')
            ->set('companyId', (string) $this->segurex->id)
            ->set('groupName', 'app-cartera')
            ->call('addGroup')
            ->assertHasErrors('groupName')
            ->assertSee('Esa regla ya existe.');

        // The same name in another company is another rule.
        $this->rules()
            ->call('open', 'group')
            ->set('companyId', (string) $this->derivados->id)
            ->set('groupName', 'APP-Cartera')
            ->call('addGroup')
            ->assertHasNoErrors();

        $this->assertSame(2, $this->cartera->accessRules()->count());
    }

    public function test_a_group_name_is_required(): void
    {
        $this->rules()->call('open', 'group')->set('groupName', ' ')->call('addGroup')->assertHasErrors('groupName');
        $this->assertSame(0, $this->cartera->accessRules()->count());
    }

    public function test_users_are_searched_within_the_chosen_company(): void
    {
        $ana = User::factory()->microsoft($this->segurex)->create(['name' => 'Ana María Pérez', 'email' => 'ana@segurex.com']);
        User::factory()->microsoft($this->derivados)->create(['name' => 'Ana Derivados', 'email' => 'ana@derivados.com']);

        $component = $this->rules()->call('open', 'user')->set('companyId', (string) $this->segurex->id);

        $component->set('userSearch', 'a')->assertDontSee('ana@segurex.com');
        $component->set('userSearch', 'ana')
            ->assertSee('Ana María Pérez')
            ->assertSee('ana@segurex.com')
            ->assertDontSee('ana@derivados.com');

        $component->call('addUser', $ana->id)->assertHasNoErrors();

        $rule = $this->cartera->accessRules()->sole();
        $this->assertSame([AppAccessRule::TYPE_USER, (string) $ana->id, $this->segurex->id], [$rule->type, $rule->value, $rule->company_id]);
    }

    public function test_an_empty_user_search_explains_who_appears_and_what_to_do(): void
    {
        User::factory()->microsoft($this->segurex)->create(['name' => 'Ana María Pérez', 'email' => 'ana@segurex.com']);

        $component = $this->rules()->call('open', 'user')->set('companyId', (string) $this->segurex->id);

        $component->set('userSearch', 'zz')
            ->assertSee('Nadie con ese nombre ha entrado al portal en esta empresa.')
            ->assertSee('Solo aparecen las personas que ya iniciaron sesión al menos una vez.')
            ->assertSeeHtml('use <strong>+ Grupo de Microsoft</strong> o pídale que entre una vez.');

        $component->set('userSearch', 'ana')
            ->assertSee('ana@segurex.com')
            ->assertDontSee('Nadie con ese nombre ha entrado al portal en esta empresa.');
    }

    public function test_a_user_of_another_company_cannot_be_added(): void
    {
        $outsider = User::factory()->microsoft($this->derivados)->create();

        $this->rules()
            ->call('open', 'user')
            ->set('companyId', (string) $this->segurex->id)
            ->call('addUser', $outsider->id)
            ->assertHasErrors('userSearch');

        $this->assertSame(0, $this->cartera->accessRules()->count());
    }

    public function test_duplicate_user_rules_are_rejected(): void
    {
        $ana = User::factory()->microsoft($this->segurex)->create();
        AppAccessRule::forUser($this->cartera, $ana);

        $this->rules()
            ->call('open', 'user')
            ->set('companyId', (string) $this->segurex->id)
            ->call('addUser', $ana->id)
            ->assertHasErrors('userSearch');

        $this->assertSame(1, $this->cartera->accessRules()->count());
    }

    public function test_a_whole_company_rule_is_added_once(): void
    {
        $this->rules()
            ->call('open', 'company')
            ->set('companyId', (string) $this->segurex->id)
            ->call('addCompany')
            ->assertHasNoErrors();

        $this->rules()
            ->call('open', 'company')
            ->set('companyId', (string) $this->segurex->id)
            ->call('addCompany')
            ->assertHasErrors('companyId')
            ->assertSee('Esa regla ya existe.');

        $rule = $this->cartera->accessRules()->sole();
        $this->assertSame([AppAccessRule::TYPE_COMPANY, null], [$rule->type, $rule->value]);
    }

    public function test_an_unknown_company_is_rejected(): void
    {
        $this->rules()->call('open', 'company')->set('companyId', '999')->call('addCompany')->assertHasErrors('companyId');
        $this->assertSame(0, $this->cartera->accessRules()->count());
    }

    public function test_a_rule_for_a_company_without_the_app_is_flagged(): void
    {
        AppAccessRule::forCompany($this->cartera, $this->derivados);

        $this->rules()->assertSee('La app no está activa en Derivados: esta regla no aplica.');

        $this->cartera->update(['available_to_all_companies' => true]);
        $this->rules()->assertDontSee('esta regla no aplica');
    }

    public function test_a_rule_is_removed_and_only_from_this_app(): void
    {
        $rule = AppAccessRule::forCompany($this->cartera, $this->segurex);
        $foreign = AppAccessRule::forCompany(App::factory()->create(), $this->segurex);

        $this->rules()->call('remove', $foreign->id)->call('remove', $rule->id);

        $this->assertModelMissing($rule);
        $this->assertModelExists($foreign);
    }

    public function test_adding_and_removing_rules_is_logged(): void
    {
        $this->rules()->call('open', 'group')->set('companyId', (string) $this->segurex->id)->set('groupName', 'APP-Cartera')->call('addGroup');
        $rule = $this->cartera->accessRules()->sole();
        $this->rules()->call('remove', $rule->id);

        $added = Activity::where('event', 'app.access_rule_added')->sole();
        $this->assertTrue($added->subject->is($this->cartera));
        $this->assertTrue($added->causer->is($this->admin));
        $this->assertSame(['type' => 'group', 'value' => 'APP-Cartera', 'company' => 'Segurex'], $added->properties['attributes']);

        $removed = Activity::where('event', 'app.access_rule_removed')->sole();
        $this->assertSame(['type' => 'group', 'value' => 'APP-Cartera', 'company' => 'Segurex'], $removed->properties['old']);
    }

    public function test_added_rules_change_what_users_see(): void
    {
        $ana = $this->member($this->segurex, ['APP-Cartera']);
        $this->actingAs($ana)->get(route('apps.open', $this->cartera))->assertNotFound();

        $this->rules()->call('open', 'group')->set('companyId', (string) $this->segurex->id)->set('groupName', 'APP-Cartera')->call('addGroup');

        $this->actingAs($ana->fresh())->get(route('apps.open', $this->cartera))->assertRedirect($this->cartera->url);
    }

    private function rules(): Testable
    {
        return Livewire::actingAs($this->admin)->test(AppAccessRules::class, ['app' => $this->cartera->fresh()]);
    }

    /**
     * @param  list<string>  $groups
     */
    private function member(Company $company, array $groups): User
    {
        $user = User::factory()->microsoft($company)->create();
        $user->syncGroups(array_map(fn (string $name) => ['id' => fake()->uuid(), 'display_name' => $name], $groups));

        return $user;
    }
}
