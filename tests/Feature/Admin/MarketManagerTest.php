<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\MarketManager;
use App\Markets\MarketSeries;
use App\Markets\MarketUnit;
use App\Models\Company;
use App\Models\MarketInput;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Administración › Mercados (odd/tasks/mercados-o5.md M4): per company,
 * the inputs its band shows and the prices typed by hand. Admins manage
 * every company; announcements editors only their own
 * (App\Policies\MarketInputPolicy), checked on every action.
 */
class MarketManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $segurex;

    private Company $sdc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00', 'America/Bogota'));

        $this->segurex = Company::factory()->create(['name' => 'Segurex LATAM SAS', 'short_name' => 'Segurex']);
        $this->sdc = Company::factory()->create(['name' => 'Soluciones y Derivados SAS', 'short_name' => 'SDC']);
        $this->admin = tap(User::factory()->create(['name' => 'Ana Pérez']))->assignRole('portal_admin');
    }

    // --- Access -----------------------------------------------------------

    public function test_the_component_refuses_people_without_the_role(): void
    {
        Livewire::actingAs(User::factory()->create(['company_id' => $this->segurex->id]))->test(MarketManager::class)->assertForbidden();
        Livewire::actingAs(tap(User::factory()->create(['company_id' => $this->segurex->id]))->assignRole('management'))->test(MarketManager::class)->assertForbidden();
        Livewire::actingAs(tap(User::factory()->create(['company_id' => null]))->assignRole('announcements_editor'))->test(MarketManager::class)->assertForbidden();
    }

    public function test_admins_pick_any_company(): void
    {
        $super = tap(User::factory()->create())->assignRole('super_admin');

        foreach ([$this->admin, $super] as $user) {
            Livewire::actingAs($user)->test(MarketManager::class)
                ->assertSet('companyId', $this->segurex->id)
                ->assertSee('Segurex')
                ->assertSee('SDC')
                ->set('companyId', $this->sdc->id)
                ->assertSet('companyId', $this->sdc->id)
                ->assertSee('Precios ingresados a mano en SDC');
        }
    }

    public function test_an_editor_gets_only_their_own_company(): void
    {
        $editor = $this->editor($this->sdc);
        MarketInput::factory()->for($this->segurex)->create(['label' => 'Latón en barra']);

        Livewire::actingAs($editor)->test(MarketManager::class)
            ->assertSet('companyId', $this->sdc->id)
            ->assertDontSee('Segurex')
            ->assertDontSee('Latón en barra');

        Livewire::actingAs($editor)->test(MarketManager::class)->set('companyId', $this->segurex->id)->assertForbidden();
    }

    public function test_an_editor_cannot_open_another_company_from_the_address(): void
    {
        Livewire::withQueryParams(['empresa' => $this->segurex->id])->actingAs($this->editor($this->sdc))->test(MarketManager::class)->assertForbidden();
        Livewire::withQueryParams(['empresa' => $this->sdc->id])->actingAs($this->editor($this->sdc))->test(MarketManager::class)->assertOk()->assertSet('companyId', $this->sdc->id);
    }

    public function test_an_editor_cannot_touch_another_company_s_prices(): void
    {
        $theirs = MarketInput::factory()->for($this->segurex)->create(['label' => 'Latón en barra']);
        $manager = Livewire::actingAs($this->editor($this->sdc))->test(MarketManager::class);

        $manager->call('editInput', $theirs->id)->assertNotFound();
        $manager->call('deleteInput', $theirs->id)->assertNotFound();

        $this->assertModelExists($theirs);
    }

    public function test_an_editor_s_codes_save_only_on_their_company(): void
    {
        Livewire::actingAs($this->editor($this->sdc))->test(MarketManager::class)
            ->set('codes', ['alkali', 'resins'])
            ->call('saveCodes')
            ->assertHasNoErrors();

        $this->assertSame(['alkali', 'resins'], $this->sdc->fresh()->market_codes);
        $this->assertNull($this->segurex->fresh()->market_codes);
    }

    public function test_a_role_taken_away_stops_the_next_action(): void
    {
        $form = $this->manager()->call('newInput')->set('label', 'Soda cáustica');
        $codes = $this->manager()->set('codes', ['zinc']);

        $this->admin->removeRole('portal_admin');

        $form->call('saveInput')->assertForbidden();
        $codes->call('saveCodes')->assertForbidden();
        $this->assertSame(0, MarketInput::count());
        $this->assertNull($this->segurex->fresh()->market_codes);
    }

    public function test_locked_properties_cannot_be_set_from_the_browser(): void
    {
        $price = MarketInput::factory()->for($this->segurex)->create();

        foreach (['editingId' => $price->id, 'showForm' => true, 'notice' => 'Hola'] as $property => $value) {
            try {
                $this->manager()->set($property, $value);
                $this->fail("{$property} could be set from the browser.");
            } catch (CannotUpdateLockedPropertyException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // --- Inputs of the band ----------------------------------------------------

    public function test_every_input_is_offered_with_its_unit_frequency_and_meaning(): void
    {
        $manager = $this->manager();

        foreach (MarketSeries::all() as $series) {
            $manager->assertSee($series->label)->assertSee($series->meaning());
            $this->assertNotSame('', $series->meaning(), $series->code);
        }

        $manager->assertSee('Siempre se muestran')
            ->assertSee('Índice · Mensual')
            ->assertSee('Dólares por barril · Diaria')
            ->assertSeeHtml('value="zinc"')
            ->assertDontSeeHtml('value="trm"');
    }

    public function test_the_chosen_inputs_are_saved_and_unknown_codes_dropped(): void
    {
        $this->segurex->update(['market_codes' => ['zinc']]);
        Activity::query()->delete();

        $manager = $this->manager()
            ->assertSet('codes', ['zinc'])
            ->set('codes', ['steel', 'trm', 'nope', 'brass', 'brass'])
            ->call('saveCodes')
            ->assertSet('codes', ['brass', 'steel'])
            ->assertSet('notice', 'Se guardaron los insumos de Segurex.');

        $this->assertSame(['brass', 'steel'], $this->segurex->fresh()->market_codes);

        $entry = Activity::query()->latest('id')->firstOrFail();
        $this->assertSame('Ana Pérez', $entry->causer?->name);
        $this->assertSame(['zinc'], $entry->properties['old']['market_codes']);

        $manager->set('codes', [])->call('saveCodes');
        $this->assertNull($this->segurex->fresh()->market_codes);
    }

    public function test_switching_company_reads_its_own_choice(): void
    {
        $this->sdc->update(['market_codes' => ['alkali']]);

        $this->manager()->set('codes', ['zinc'])->set('companyId', $this->sdc->id)->assertSet('codes', ['alkali']);
    }

    // --- Manual prices ---------------------------------------------------------

    public function test_a_price_is_added_with_its_author(): void
    {
        $this->manager()
            ->call('newInput')
            ->set('label', '  Soda   cáustica ')
            ->set('unit', MarketUnit::PesosPerKilo->value)
            ->set('value', '4.200,50')
            ->set('validOn', '2026-11-03')
            ->set('source', 'Cotización Brenntag')
            ->call('saveInput')
            ->assertHasNoErrors()
            ->assertSet('showForm', false)
            ->assertSet('notice', 'Se agregó el precio de «Soda cáustica».')
            ->assertSee('$4.200,50/kg');

        $price = MarketInput::query()->sole();
        $this->assertSame($this->segurex->id, $price->company_id);
        $this->assertSame('Soda cáustica', $price->label);
        $this->assertSame(MarketUnit::PesosPerKilo, $price->unit);
        $this->assertSame('4200.5000', $price->value);
        $this->assertSame('2026-11-03', $price->valid_on->toDateString());
        $this->assertSame('Cotización Brenntag', $price->source);
        $this->assertSame($this->admin->id, $price->created_by);

        $entry = Activity::query()->where('log_name', MarketInput::AUDIT_LOG)->latest('id')->firstOrFail();
        $this->assertSame('created', $entry->event);
        $this->assertSame('Ana Pérez', $entry->causer?->name);
    }

    public function test_a_name_reuses_the_spelling_already_in_use(): void
    {
        MarketInput::factory()->for($this->segurex)->create(['label' => 'Soda Cáustica']);

        $this->fill($this->manager()->call('newInput'), label: 'soda caustica')->call('saveInput')->assertHasNoErrors();

        $this->assertSame(['Soda Cáustica'], MarketInput::query()->distinct()->pluck('label')->all());
    }

    public function test_the_form_explains_each_mistake_in_spanish(): void
    {
        $manager = $this->manager()->call('newInput');

        $this->fill($manager, label: '', value: '', validOn: '', unit: '')->call('saveInput')->assertHasErrors([
            'label' => 'Escribe el nombre del insumo.',
            'unit' => 'Elige la unidad del precio.',
            'value' => 'Escribe el valor.',
            'validOn' => 'Escribe la fecha del precio.',
        ]);

        $this->fill($manager, label: str_repeat('a', 41), value: '0', validOn: '2026-11-05', unit: 'usd_barrel', source: str_repeat('b', 81))
            ->call('saveInput')
            ->assertHasErrors([
                'label' => 'Usa como máximo 40 caracteres.',
                'unit' => 'Elige una de las unidades de la lista.',
                'value' => 'El valor debe ser mayor que cero.',
                'validOn' => 'La fecha no puede ser futura.',
                'source' => 'Usa como máximo 80 caracteres.',
            ]);

        $this->fill($manager, value: 'mucho', validOn: '3/11/2026')->call('saveInput')->assertHasErrors([
            'value' => 'Escribe el valor como un número, por ejemplo 4200,50.',
            'validOn' => 'Escribe una fecha válida.',
        ]);

        $this->fill($manager, value: '10000000000')->call('saveInput')->assertHasErrors(['value' => 'El valor es demasiado grande.']);
        $this->fill($manager, value: '1,23456')->call('saveInput')->assertHasErrors(['value' => 'Usa como máximo cuatro decimales.']);

        $this->assertSame(0, MarketInput::count());
    }

    public function test_today_in_bogota_is_a_valid_date(): void
    {
        $this->fill($this->manager()->call('newInput'), validOn: '2026-11-04')->call('saveInput')->assertHasNoErrors();

        $this->assertSame(1, MarketInput::count());
    }

    public function test_a_price_is_edited(): void
    {
        $price = MarketInput::factory()->for($this->segurex)->create(['label' => 'PET', 'value' => '5100', 'valid_on' => '2026-10-20', 'source' => null]);

        $this->manager()
            ->call('editInput', $price->id)
            ->assertSet('label', 'PET')
            ->assertSet('value', '5100')
            ->assertSet('validOn', '2026-10-20')
            ->set('value', '5150,25')
            ->call('saveInput')
            ->assertHasNoErrors()
            ->assertSet('notice', 'Se guardaron los cambios del precio de «PET».');

        $this->assertSame('5150.2500', $price->fresh()->value);
        $this->assertNull($price->fresh()->created_by, 'Editing keeps the author.');

        $entry = Activity::query()->where('log_name', MarketInput::AUDIT_LOG)->latest('id')->firstOrFail();
        $this->assertSame('updated', $entry->event);
        $this->assertSame(['value'], array_keys($entry->properties['attributes']));
    }

    public function test_a_price_is_deleted(): void
    {
        $price = MarketInput::factory()->for($this->segurex)->create(['label' => 'HDPE']);

        $this->manager()
            ->assertSeeHtml('wire:confirm="¿Borrar el precio de «HDPE» del')
            ->call('deleteInput', $price->id)
            ->assertSet('notice', 'Se borró el precio de «HDPE».')
            ->assertSee('Segurex todavía no tiene precios ingresados a mano.');

        $this->assertModelMissing($price);
        $this->assertSame('deleted', Activity::query()->where('log_name', MarketInput::AUDIT_LOG)->latest('id')->firstOrFail()->event);
    }

    public function test_the_list_is_newest_first_and_capped(): void
    {
        MarketInput::factory()->for($this->segurex)->create(['label' => 'Viejo', 'valid_on' => '2026-01-01']);
        MarketInput::factory()->for($this->segurex)->create(['label' => 'Nuevo', 'valid_on' => '2026-11-03']);
        MarketInput::factory()->for($this->sdc)->create(['label' => 'De SDC']);

        $this->manager()->assertSeeInOrder(['Nuevo', 'Viejo'])->assertDontSee('De SDC');

        MarketInput::factory()->for($this->segurex)->count(MarketManager::LIST_LIMIT)->create(['label' => 'Reciente', 'valid_on' => '2026-11-02']);

        $this->manager()
            ->assertDontSee('Viejo')
            ->assertSee('Se muestran los '.MarketManager::LIST_LIMIT.' precios más recientes de '.(MarketManager::LIST_LIMIT + 2).'.');
    }

    private function manager(): Testable
    {
        return Livewire::actingAs($this->admin)->test(MarketManager::class);
    }

    private function editor(Company $company): User
    {
        return tap(User::factory()->create(['company_id' => $company->id]))->assignRole('announcements_editor');
    }

    private function fill(Testable $manager, string $label = 'Soda cáustica', string $value = '4200', string $validOn = '2026-11-03', string $unit = 'cop_kg', string $source = ''): Testable
    {
        return $manager->set('label', $label)->set('value', $value)->set('validOn', $validOn)->set('unit', $unit)->set('source', $source);
    }
}
