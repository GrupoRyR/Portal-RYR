<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\MarketManager;
use App\Markets\MarketBoard;
use App\Models\Company;
use App\Models\MarketInput;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Amounts and dates of a manual price in Administración › Mercados: the
 * Colombian way of writing numbers («4.200,50», «4.200»), no guessing on
 * an ambiguous point, what was typed kept on an error, the stored
 * precision shown, and no date older than the band reads.
 */
class MarketManagerAmountsTest extends TestCase
{
    use RefreshDatabase;

    private const COMMA = 'Usa coma para los decimales, por ejemplo 4.200,50.';

    private User $admin;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00', 'America/Bogota'));
        $this->company = Company::factory()->create(['short_name' => 'Segurex']);
        $this->admin = tap(User::factory()->create())->assignRole('portal_admin');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function colombianAmounts(): array
    {
        return [
            'thousands with a point' => ['4.200', '4200.0000'],
            'another thousands' => ['1.350', '1350.0000'],
            'millions' => ['4.200.000', '4200000.0000'],
            'thousands and decimals' => ['4.200,50', '4200.5000'],
            'decimal comma' => ['4200,5', '4200.5000'],
            'plain' => ['4200', '4200.0000'],
            'four decimals' => ['0,1234', '0.1234'],
        ];
    }

    #[DataProvider('colombianAmounts')]
    public function test_a_colombian_amount_is_read_as_a_person_means_it(string $typed, string $stored): void
    {
        $this->form(value: $typed)->call('saveInput')->assertHasNoErrors();

        $this->assertSame($stored, MarketInput::query()->sole()->value);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function ambiguousAmounts(): array
    {
        return [
            'decimal point' => ['4.2'],
            'two decimals with a point' => ['1.35'],
            'mixed, English style' => ['1,234.5'],
            'four digits after the point' => ['4200.5'],
        ];
    }

    #[DataProvider('ambiguousAmounts')]
    public function test_a_decimal_point_is_refused_rather_than_guessed(string $typed): void
    {
        $this->form(value: $typed)
            ->call('saveInput')
            ->assertHasErrors(['value' => self::COMMA])
            ->assertSet('value', $typed);

        $this->assertSame(0, MarketInput::count());
    }

    public function test_a_failed_save_keeps_exactly_what_was_typed(): void
    {
        $this->form(label: '  Soda   cáustica ', value: '4.200,50', validOn: '2026-11-05', source: '  Brenntag  ')
            ->call('saveInput')
            ->assertHasErrors(['validOn'])
            ->assertSet('label', '  Soda   cáustica ')
            ->assertSet('value', '4.200,50')
            ->assertSet('source', '  Brenntag  ');
    }

    public function test_a_date_older_than_the_band_reads_is_refused(): void
    {
        $first = CarbonImmutable::parse('2026-11-04')->subDays(MarketBoard::WINDOW_DAYS);

        // The message has a colon, which assertHasErrors() would read as a rule parameter.
        $old = $this->form(validOn: $first->subDay()->toDateString())->call('saveInput')->assertHasErrors(['validOn']);
        $this->assertSame(
            'El inicio solo muestra precios de los últimos '.MarketBoard::WINDOW_DAYS.' días: usa una fecha desde el 30 sep 2025.',
            $old->errors()->first('validOn'),
        );

        $this->form(validOn: $first->toDateString())->call('saveInput')->assertHasNoErrors();
        $this->assertSame('2025-09-30', MarketInput::query()->sole()->valid_on->toDateString());
    }

    public function test_the_list_shows_the_stored_precision(): void
    {
        MarketInput::factory()->for($this->company)->create(['label' => 'Cloro', 'value' => '0.1234', 'valid_on' => '2026-11-03']);
        MarketInput::factory()->for($this->company)->create(['label' => 'Soda', 'value' => '4200.5000', 'valid_on' => '2026-11-02']);
        MarketInput::factory()->for($this->company)->create(['label' => 'PET', 'value' => '5100.1230', 'valid_on' => '2026-11-01']);

        $this->manager()
            ->assertSee('$0,1234/kg')
            ->assertSee('$4.200,50/kg')
            ->assertSee('$5.100,123/kg');
    }

    public function test_the_screen_says_how_many_prices_the_band_shows(): void
    {
        $this->manager()->assertSee('La banda muestra hasta '.MarketBoard::MANUAL_LIMIT.' precios manuales');
    }

    private function manager(): Testable
    {
        return Livewire::actingAs($this->admin)->test(MarketManager::class);
    }

    private function form(string $label = 'Soda cáustica', string $value = '4200', string $validOn = '2026-11-03', string $source = ''): Testable
    {
        return $this->manager()->call('newInput')
            ->set('label', $label)
            ->set('value', $value)
            ->set('unit', 'cop_kg')
            ->set('validOn', $validOn)
            ->set('source', $source);
    }
}
