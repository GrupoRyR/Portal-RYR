<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\DirectoryEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `buscar_personas`: the /personas search over each account's company
 * directory (DirectoryEntry::companyIdFor()), with what /personas shows and
 * nothing else.
 */
class BuscarPersonasTest extends TestCase
{
    use ConnectsTwoAccounts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectTwoAccounts();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function search(string $text): array
    {
        return $this->toolResult('buscar_personas', ['texto' => $text])['personas'];
    }

    public function test_both_directories_are_searched_and_each_person_is_labelled(): void
    {
        DirectoryEntry::factory()->forCompany($this->segurex)->create([
            'display_name' => 'Andrés Gómez',
            'job_title' => 'Analista de compras',
            'department' => 'Compras',
            'office_location' => 'Bogotá',
            'business_phone' => '+57 601 555 1234',
            'email' => 'andres.gomez@segurex.com',
        ]);
        DirectoryEntry::factory()->forCompany($this->derivados)->create([
            'display_name' => 'Andrea Gómez',
            'job_title' => null,
            'department' => 'Compras',
            'office_location' => null,
            'business_phone' => null,
            'email' => 'andrea.gomez@derivados.com',
        ]);
        DirectoryEntry::factory()->forCompany($this->segurex)->create(['display_name' => 'Pedro Pérez', 'department' => 'Ventas']);

        $this->assertSame([
            [
                'nombre' => 'Andrea Gómez',
                'cargo' => null,
                'area' => 'Compras',
                'oficina' => null,
                'correo' => 'andrea.gomez@derivados.com',
                'telefono_oficina' => null,
                'empresa' => 'Derivados',
            ],
            [
                'nombre' => 'Andrés Gómez',
                'cargo' => 'Analista de compras',
                'area' => 'Compras',
                'oficina' => 'Bogotá',
                'correo' => 'andres.gomez@segurex.com',
                'telefono_oficina' => '+57 601 555 1234',
                'empresa' => 'Segurex',
            ],
        ], $this->search('gomez compras'));
    }

    public function test_no_birthday_mobile_or_anything_the_portal_does_not_show(): void
    {
        $account = User::factory()->microsoft($this->segurex)->birthday(3, 14)->create();
        DirectoryEntry::factory()->forCompany($this->segurex)->create([
            'azure_oid' => $account->azure_oid,
            'display_name' => 'Laura Rey',
            'hire_date' => '2015-03-02',
        ]);

        $result = $this->toolResult('buscar_personas', ['texto' => 'laura']);
        $json = json_encode($result, JSON_UNESCAPED_UNICODE);

        $this->assertCount(1, $result['personas']);
        $this->assertSame(['nombre', 'cargo', 'area', 'oficina', 'correo', 'telefono_oficina', 'empresa'], array_keys($result['personas'][0]));
        $this->assertStringNotContainsString('2015', $json);
        $this->assertNoKeys($result, [
            'celular', 'movil', 'mobile', 'mobile_phone', 'cumpleanos', 'birthday_month', 'birthday_day',
            'ano_nacimiento', 'fecha_nacimiento', 'hire_date', 'fecha_ingreso', 'azure_oid', 'tenant_id', 'id',
        ]);
    }

    public function test_a_directory_outside_the_connection_is_never_searched(): void
    {
        DirectoryEntry::factory()->forCompany($this->other)->create(['display_name' => 'Marta Otra']);

        $this->assertSame([], $this->search('marta'));
    }

    public function test_after_unlinking_the_other_directory_is_no_longer_searched(): void
    {
        DirectoryEntry::factory()->forCompany($this->derivados)->create(['display_name' => 'Andrea Gómez']);
        $this->assertCount(1, $this->search('andrea'));

        $this->unlinkTheLinkedAccount();

        $this->assertSame([], $this->search('andrea'));
    }

    #[DataProvider('linkedAccountThatCanNoLongerConnect')]
    public function test_a_linked_account_that_can_no_longer_connect_is_not_read_for(string $case): void
    {
        DirectoryEntry::factory()->forCompany($this->derivados)->create(['display_name' => 'Andrea Gómez']);

        $this->makeLinkedAccountUnusable($case);

        $this->assertSame([], $this->search('andrea'));
    }

    public function test_at_most_twenty_by_name(): void
    {
        foreach (range(0, 29) as $i) {
            DirectoryEntry::factory()->forCompany($i % 2 === 0 ? $this->segurex : $this->derivados)->create([
                'display_name' => sprintf('Ramírez %02d', $i),
            ]);
        }

        $result = $this->toolResult('buscar_personas', ['texto' => 'ramirez']);

        $this->assertSame(20, $result['limite']);
        $this->assertSame(
            array_map(fn (int $i) => sprintf('Ramírez %02d', $i), range(0, 19)),
            array_column($result['personas'], 'nombre'),
        );
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badTexts(): array
    {
        return [
            'one letter' => ['a'],
            'only spaces' => ['    '],
            'too long' => [str_repeat('a', 61)],
            'not text' => [42],
        ];
    }

    #[DataProvider('badTexts')]
    public function test_a_text_out_of_range_is_refused(mixed $text): void
    {
        DirectoryEntry::factory()->forCompany($this->segurex)->create();

        $this->assertStringContainsString('texto', $this->toolError('buscar_personas', ['texto' => $text]));
    }

    public function test_the_text_is_required(): void
    {
        $this->assertStringContainsString('texto', $this->toolError('buscar_personas'));
    }
}
