<?php

namespace Tests\Feature\Markets;

use App\Audit\AuditEntry;
use App\Audit\AuditPresenter;
use App\Markets\MarketUnit;
use App\Models\Company;
use App\Models\MarketInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Auditoría rows of Administración › Mercados (odd/tasks/mercados-o5.md
 * M4): the inputs a company chose and every manual price, with Spanish
 * labels, units, numbers and dates.
 */
class MarketAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manual_price_is_named_and_its_fields_are_labelled(): void
    {
        $company = Company::factory()->create(['short_name' => 'SDC']);
        $price = MarketInput::factory()->for($company)->create([
            'label' => 'Soda cáustica',
            'unit' => MarketUnit::PesosPerKilo,
            'value' => '4200',
            'valid_on' => '2026-11-03',
            'source' => 'Cotización Brenntag',
        ]);

        $created = $this->present($this->latest());
        $this->assertSame('Creó el precio manual «Soda cáustica»', $created->what);
        $changes = collect($created->changes)->keyBy('label');
        $this->assertSame('Soda cáustica', $changes['Nombre']['new']);
        $this->assertSame('Pesos por kilo ($/kg)', $changes['Unidad']['new']);
        $this->assertSame('4.200,00', $changes['Valor']['new']);
        $this->assertSame('3 nov 2026', $changes['Fecha']['new']);
        $this->assertSame('Cotización Brenntag', $changes['Fuente']['new']);
        $this->assertSame('SDC', $changes['Empresa']['new']);

        $price->update(['value' => '4350.1234', 'unit' => MarketUnit::UsdPerTonne]);
        $updated = $this->present($this->latest());
        $this->assertSame('Cambió el precio manual «Soda cáustica»', $updated->what);
        $this->assertEqualsCanonicalizing([
            ['label' => 'Unidad', 'old' => 'Pesos por kilo ($/kg)', 'new' => 'Dólares por tonelada (US$/t)'],
            ['label' => 'Valor', 'old' => '4.200,00', 'new' => '4.350,1234'],
        ], $updated->changes);

        $price->delete();
        $this->assertSame('Borró el precio manual «Soda cáustica»', $this->present($this->latest())->what);
    }

    public function test_the_inputs_of_a_company_show_by_name(): void
    {
        $company = Company::factory()->create(['name' => 'Segurex Latam']);
        Activity::query()->delete();

        $company->update(['market_codes' => ['zinc', 'brass']]);

        $entry = $this->present($this->latest());
        $this->assertSame('Cambió la empresa Segurex Latam', $entry->what);
        $this->assertSame([
            ['label' => 'Insumos de Mercados', 'old' => '—', 'new' => 'Zinc (US$/t), Latón (índice)'],
        ], $entry->changes);
    }

    private function latest(): Activity
    {
        return Activity::query()->latest('id')->firstOrFail();
    }

    private function present(Activity $activity): AuditEntry
    {
        return (new AuditPresenter)->activity($activity);
    }
}
