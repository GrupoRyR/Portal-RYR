<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\Announcement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `anuncios_pendientes`: the mandatory announcements each account of the
 * connection sees right now and the person has not confirmed yet, newest
 * first, labelled with the companies of the accounts that see them.
 */
class AnunciosPendientesTest extends TestCase
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
    private function pending(): array
    {
        return $this->toolResult('anuncios_pendientes')['anuncios'];
    }

    public function test_both_accounts_pending_announcements_come_labelled_with_their_companies(): void
    {
        $forAll = Announcement::factory()->mandatory()->create(['title' => 'Para todos', 'starts_at' => now()->subDays(3)]);
        $segurex = Announcement::factory()->mandatory()->forCompanies($this->segurex)->create(['title' => 'Solo Segurex', 'starts_at' => now()->subDays(2)]);
        $derivados = Announcement::factory()->mandatory()->forCompanies($this->derivados)->create(['title' => 'Solo Derivados', 'starts_at' => now()->subDay()]);

        $this->assertSame([
            [
                'id' => $derivados->id,
                'titulo' => 'Solo Derivados',
                'empresas' => ['Derivados'],
                'publicado' => '2026-11-03T09:00:00-05:00',
                'publicado_texto' => 'Martes 3 de noviembre de 2026, 09:00',
                'url' => route('announcements.show', $derivados->id),
            ],
            [
                'id' => $segurex->id,
                'titulo' => 'Solo Segurex',
                'empresas' => ['Segurex'],
                'publicado' => '2026-11-02T09:00:00-05:00',
                'publicado_texto' => 'Lunes 2 de noviembre de 2026, 09:00',
                'url' => route('announcements.show', $segurex->id),
            ],
            [
                'id' => $forAll->id,
                'titulo' => 'Para todos',
                'empresas' => ['Segurex', 'Derivados'],
                'publicado' => '2026-11-01T09:00:00-05:00',
                'publicado_texto' => 'Domingo 1 de noviembre de 2026, 09:00',
                'url' => route('announcements.show', $forAll->id),
            ],
        ], $this->pending());
    }

    public function test_an_announcement_of_a_company_outside_the_connection_never_appears(): void
    {
        Announcement::factory()->mandatory()->forCompanies($this->other)->create(['title' => 'Solo Otra']);
        // Addressed to Segurex and Otra: listed for Segurex only.
        $shared = Announcement::factory()->mandatory()->forCompanies($this->segurex, $this->other)->create(['title' => 'Segurex y Otra']);

        $pending = $this->pending();

        $this->assertSame([$shared->id], array_column($pending, 'id'));
        $this->assertSame(['Segurex'], $pending[0]['empresas']);
        $this->assertNotContains('Otra', array_merge(...array_column($pending, 'empresas')));
    }

    public function test_read_and_not_mandatory_and_unpublished_announcements_are_left_out(): void
    {
        $read = Announcement::factory()->mandatory()->create(['title' => 'Leído']);
        $read->markReadBy($this->linked);
        Announcement::factory()->create(['title' => 'Sin lectura obligatoria']);
        Announcement::factory()->mandatory()->scheduled()->create(['title' => 'Programado']);
        Announcement::factory()->mandatory()->expired()->create(['title' => 'Vencido']);
        Announcement::factory()->mandatory()->withdrawn()->create(['title' => 'Retirado']);
        $pending = Announcement::factory()->mandatory()->create(['title' => 'Pendiente']);

        $this->assertSame([$pending->id], array_column($this->pending(), 'id'));
    }

    public function test_a_read_with_either_account_counts_for_the_person(): void
    {
        $announcement = Announcement::factory()->mandatory()->forCompanies($this->derivados)->create();
        $announcement->markReadBy($this->approver);

        $this->assertSame([], $this->pending());
    }

    public function test_after_unlinking_the_other_company_no_longer_appears(): void
    {
        Announcement::factory()->mandatory()->forCompanies($this->derivados)->create(['title' => 'Solo Derivados']);
        $forAll = Announcement::factory()->mandatory()->create(['title' => 'Para todos']);
        $this->assertCount(2, $this->pending());

        $this->unlinkTheLinkedAccount();

        $pending = $this->pending();
        $this->assertSame([$forAll->id], array_column($pending, 'id'));
        $this->assertSame(['Segurex'], $pending[0]['empresas']);
    }

    #[DataProvider('linkedAccountThatCanNoLongerConnect')]
    public function test_a_linked_account_that_can_no_longer_connect_is_not_read_for(string $case): void
    {
        Announcement::factory()->mandatory()->forCompanies($this->derivados)->create();

        $this->makeLinkedAccountUnusable($case);

        $this->assertSame([], $this->pending());
    }

    public function test_at_most_twenty_newest_first(): void
    {
        foreach (range(1, 15) as $day) {
            Announcement::factory()->mandatory()->forCompanies($this->segurex)->create(['title' => "S{$day}", 'starts_at' => now()->subDays($day)->subMinute()]);
            Announcement::factory()->mandatory()->forCompanies($this->derivados)->create(['title' => "D{$day}", 'starts_at' => now()->subDays($day)]);
        }

        $result = $this->toolResult('anuncios_pendientes');

        $this->assertSame(20, $result['limite']);
        $this->assertCount(20, $result['anuncios']);
        $this->assertSame(['D1', 'S1', 'D2', 'S2'], array_slice(array_column($result['anuncios'], 'titulo'), 0, 4));
        $this->assertSame(['D10', 'S10'], array_slice(array_column($result['anuncios'], 'titulo'), 18, 2));
    }
}
