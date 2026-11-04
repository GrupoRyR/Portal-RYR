<?php

namespace Tests\Feature\Home;

use App\Markets\MarketUnit;
use App\Models\Company;
use App\Models\MarketInput;
use App\Models\MarketRate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Home\Concerns\ReadsTheHome;
use Tests\TestCase;

/**
 * The prices typed in Administración › Mercados show in the «Mercados» band
 * of their company only, after the automatic figures, with their date and
 * their change against the previous price of the same name
 * (odd/tasks/mercados-o5.md M4).
 */
class HomeManualPricesTest extends TestCase
{
    use ReadsTheHome;
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00', 'America/Bogota'));
        $this->company = Company::factory()->create(['name' => 'Soluciones y Derivados']);
        $this->user = User::factory()->microsoft($this->company)->create();
        $this->openableApp();
    }

    public function test_the_latest_price_of_each_name_shows_with_its_change_and_date(): void
    {
        MarketRate::factory()->trm()->create(['value' => 3861.38, 'valid_from' => '2026-11-04']);
        MarketInput::factory()->for($this->company)->create(['label' => 'Soda cáustica', 'unit' => MarketUnit::PesosPerKilo, 'value' => 4100, 'valid_on' => '2026-10-01']);
        $soda = MarketInput::factory()->for($this->company)->create(['label' => 'Soda cáustica', 'unit' => MarketUnit::PesosPerKilo, 'value' => 4200, 'valid_on' => '2026-11-03']);

        $response = $this->home();
        $figure = $this->element($response, 'data-market', "input-{$soda->id}");

        $this->assertStringContainsString('Soda cáustica', $figure);
        $this->assertStringContainsString('$4.200,00/kg', $figure);
        $this->assertStringContainsString('Subió 100,00 pesos por kilo (2,44 %)', $figure);
        $this->assertMatchesRegularExpression('/data-market-change="up"[^>]*class="[^"]*\bgood\b[^"]*"[^>]*>▲ 2,44 %/u', $figure);
        $this->assertStringContainsString('datetime="2026-11-03"', $figure);
        $this->assertStringContainsString('precio del 3 de noviembre de 2026', $figure);

        // After the automatic figures.
        $content = $response->getContent();
        $this->assertLessThan(strpos($content, "data-market=\"input-{$soda->id}\""), strpos($content, 'data-market="trm"'));
    }

    public function test_a_company_with_only_manual_prices_still_gets_the_band(): void
    {
        $pet = MarketInput::factory()->for($this->company)->create(['label' => 'PET', 'unit' => MarketUnit::UsdPerTonne, 'value' => 1450, 'valid_on' => '2026-11-02']);

        $figure = $this->element($this->home(), 'data-market', "input-{$pet->id}");

        $this->assertStringContainsString('US$1.450,00/t', $figure);
    }

    public function test_another_company_never_sees_them(): void
    {
        MarketRate::factory()->trm()->create(['valid_from' => '2026-11-04']);
        $soda = MarketInput::factory()->for($this->company)->create(['label' => 'Soda cáustica', 'valid_on' => '2026-11-03']);
        $segurex = Company::factory()->create(['name' => 'Segurex']);
        $other = User::factory()->microsoft($segurex)->create();
        $this->openableApp(company: $segurex);

        $response = $this->home(as: $other);

        $this->assertNoElement($response, 'data-market', "input-{$soda->id}");
        $response->assertDontSee('Soda cáustica');
    }

    public function test_a_name_is_escaped(): void
    {
        $price = MarketInput::factory()->for($this->company)->create(['label' => '<b>Ácido</b>', 'valid_on' => '2026-11-03']);

        $response = $this->home();

        $response->assertDontSee('<b>Ácido</b>', false);
        $this->assertStringContainsString('<b>Ácido</b>', $this->element($response, 'data-market', "input-{$price->id}"));
    }
}
