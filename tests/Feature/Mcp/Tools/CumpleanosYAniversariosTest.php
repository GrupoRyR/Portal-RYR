<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\Company;
use App\Models\DirectoryEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `cumpleanos_y_aniversarios`: «Cumpleaños y aniversarios» of «Mi día»
 * for each account's company directory, within the next `dias` days: only
 * birthdays people chose to show (day and month, never a year) and work
 * anniversaries with the years completed.
 */
class CumpleanosYAniversariosTest extends TestCase
{
    use ConnectsTwoAccounts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectTwoAccounts();
    }

    /**
     * A person of the company's directory; with a birthday, their own
     * account holds it (shown or not).
     *
     * @param  array{0: int, 1: int, 2?: bool}|null  $birthday  Month, day, shown.
     */
    private function person(Company $company, string $name, ?string $hired = null, ?array $birthday = null, ?User $account = null): DirectoryEntry
    {
        $oid = $account->azure_oid ?? (string) Str::uuid();

        if ($birthday !== null) {
            $attributes = ['birthday_month' => $birthday[0], 'birthday_day' => $birthday[1], 'show_birthday' => $birthday[2] ?? true];
            $account === null
                ? User::factory()->microsoft($company)->create(['azure_oid' => $oid, ...$attributes])
                : $account->forceFill($attributes)->save();
        }

        return DirectoryEntry::factory()->forCompany($company)->create([
            'azure_oid' => $oid,
            'display_name' => $name,
            'hire_date' => $hired,
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<array<string, mixed>>
     */
    private function celebrations(array $arguments = []): array
    {
        return $this->toolResult('cumpleanos_y_aniversarios', $arguments)['celebraciones'];
    }

    public function test_shown_birthdays_and_anniversaries_of_both_directories_come_labelled(): void
    {
        $this->person($this->segurex, 'Ana Ruiz', birthday: [11, 6]);
        $this->person($this->segurex, 'Beto Gil', birthday: [11, 5, false]);
        $this->person($this->derivados, 'Carla Díaz', hired: '2021-11-09');
        // Hired this year: nothing to celebrate yet.
        $this->person($this->segurex, 'Elena Mora', hired: '2026-11-06');

        $this->assertSame([
            [
                'nombre' => 'Ana Ruiz',
                'tipo' => 'Cumpleaños',
                'detalle' => 'Cumpleaños',
                'anos_cumplidos' => null,
                'fecha' => '2026-11-06T00:00:00-05:00',
                'fecha_texto' => 'Viernes 6 de noviembre',
                'empresas' => ['Segurex'],
            ],
            [
                'nombre' => 'Carla Díaz',
                'tipo' => 'Aniversario',
                'detalle' => '5 años en Derivados',
                'anos_cumplidos' => 5,
                'fecha' => '2026-11-09T00:00:00-05:00',
                'fecha_texto' => 'Lunes 9 de noviembre',
                'empresas' => ['Derivados'],
            ],
        ], $this->celebrations());
    }

    public function test_hidden_birthdays_birth_years_hire_dates_and_contact_data_never_appear(): void
    {
        $this->person($this->segurex, 'Beto Gil', birthday: [11, 5, false]);
        $this->person($this->derivados, 'Carla Díaz', hired: '2021-11-09', birthday: [11, 7]);

        $result = $this->toolResult('cumpleanos_y_aniversarios');
        $json = json_encode($result, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('Beto', $json);
        $this->assertStringNotContainsString('2021', $json);
        $this->assertStringNotContainsString('@', $json);
        $this->assertStringNotContainsString('teams', $json);
        $this->assertNoKeys($result, [
            'celular', 'movil', 'mobile', 'mobile_phone', 'telefono', 'telefono_oficina', 'correo', 'email',
            'ano_nacimiento', 'fecha_nacimiento', 'birthday_year', 'birthday_month', 'birthday_day',
            'fecha_ingreso', 'hire_date', 'url',
        ]);
    }

    public function test_the_same_birthday_seen_by_both_accounts_comes_once_with_both_companies(): void
    {
        $this->person($this->segurex, 'Jhon Castro', birthday: [11, 8], account: $this->approver);
        $this->person($this->derivados, 'Jhon Castro', birthday: [11, 8], account: $this->linked);

        $celebrations = $this->celebrations();

        $this->assertCount(1, $celebrations);
        $this->assertSame(['Segurex', 'Derivados'], $celebrations[0]['empresas']);
    }

    public function test_a_company_outside_the_connection_never_appears(): void
    {
        $this->person($this->other, 'Dario Paz', hired: '2020-11-05', birthday: [11, 5]);

        $this->assertSame([], $this->celebrations());
    }

    public function test_the_window_is_seven_days_by_default_from_today_up_to_thirty_one(): void
    {
        $this->person($this->segurex, 'Gina Hoy', birthday: [11, 4]);
        $this->person($this->segurex, 'Hugo Once', birthday: [11, 11]);
        $this->person($this->segurex, 'Iris Doce', birthday: [11, 12]);
        $this->person($this->derivados, 'Juan Fin', hired: '2019-12-05');
        $this->person($this->derivados, 'Kika Tarde', hired: '2019-12-06');

        $this->assertSame(['Gina Hoy', 'Hugo Once'], array_column($this->celebrations(), 'nombre'));
        $this->assertSame(['Gina Hoy'], array_column($this->celebrations(['dias' => 0]), 'nombre'));

        $month = $this->celebrations(['dias' => 31]);
        $this->assertSame(['Gina Hoy', 'Hugo Once', 'Iris Doce', 'Juan Fin'], array_column($month, 'nombre'));
        $this->assertSame([null, null, null, 7], array_column($month, 'anos_cumplidos'));
    }

    public function test_after_unlinking_the_other_directory_no_longer_appears(): void
    {
        $this->person($this->derivados, 'Carla Díaz', hired: '2021-11-09');
        $this->assertCount(1, $this->celebrations());

        $this->unlinkTheLinkedAccount();

        $this->assertSame([], $this->celebrations());
    }

    #[DataProvider('linkedAccountThatCanNoLongerConnect')]
    public function test_a_linked_account_that_can_no_longer_connect_is_not_read_for(string $case): void
    {
        $this->person($this->derivados, 'Carla Díaz', hired: '2021-11-09');

        $this->makeLinkedAccountUnusable($case);

        $this->assertSame([], $this->celebrations());
    }

    public function test_at_most_thirty(): void
    {
        foreach (range(0, 39) as $i) {
            $this->person($i % 2 === 0 ? $this->segurex : $this->derivados, sprintf('Persona %02d', $i), birthday: [11, 4 + $i % 7]);
        }

        $result = $this->toolResult('cumpleanos_y_aniversarios');

        $this->assertSame(30, $result['limite']);
        $this->assertCount(30, $result['celebraciones']);
        $this->assertSame('2026-11-04T00:00:00-05:00', $result['celebraciones'][0]['fecha']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badDays(): array
    {
        return ['negative' => [-1], 'too many' => [32], 'text' => ['siete']];
    }

    #[DataProvider('badDays')]
    public function test_days_out_of_range_are_refused(mixed $days): void
    {
        $this->assertStringContainsString('dias', $this->toolError('cumpleanos_y_aniversarios', ['dias' => $days]));
    }
}
