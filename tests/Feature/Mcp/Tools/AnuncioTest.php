<?php

namespace Tests\Feature\Mcp\Tools;

use App\Mcp\Tools\Anuncio;
use App\Models\Announcement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `anuncio`: one announcement an account of the connection sees right now,
 * with its plain text, dates and the person's read state. One no account
 * sees is not found, the same as one that does not exist.
 */
class AnuncioTest extends TestCase
{
    use ConnectsTwoAccounts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectTwoAccounts();
    }

    public function test_an_announcement_of_the_linked_company_comes_whole(): void
    {
        $announcement = Announcement::factory()->mandatory()->forCompanies($this->derivados)->create([
            'title' => 'Cambio de horario',
            'body' => 'Desde el lunes abrimos a las <8>. Trae tu carné & tu casco.',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(6),
        ]);

        $this->assertSame(['anuncio' => [
            'id' => $announcement->id,
            'titulo' => 'Cambio de horario',
            'texto' => 'Desde el lunes abrimos a las <8>. Trae tu carné & tu casco.',
            'empresas' => ['Derivados'],
            'publicado' => '2026-11-03T09:00:00-05:00',
            'publicado_texto' => 'Martes 3 de noviembre de 2026, 09:00',
            'vence' => '2026-11-10T09:00:00-05:00',
            'vence_texto' => 'Martes 10 de noviembre de 2026, 09:00',
            'requiere_lectura' => true,
            'leido' => false,
            'leido_el' => null,
            'url' => route('announcements.show', $announcement->id),
        ]], $this->toolResult('anuncio', ['id' => $announcement->id]));
    }

    public function test_the_read_state_is_the_person_s_and_both_companies_are_listed(): void
    {
        $announcement = Announcement::factory()->mandatory()->create();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 07:00', 'America/Bogota'));
        $announcement->markReadBy($this->linked);
        $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00', 'America/Bogota'));

        $result = $this->toolResult('anuncio', ['id' => $announcement->id])['anuncio'];

        $this->assertSame(['Segurex', 'Derivados'], $result['empresas']);
        $this->assertTrue($result['leido']);
        $this->assertSame('2026-11-04T07:00:00-05:00', $result['leido_el']);
    }

    public function test_one_that_does_not_require_reading_has_no_read_state(): void
    {
        $announcement = Announcement::factory()->create();

        $result = $this->toolResult('anuncio', ['id' => $announcement->id])['anuncio'];

        $this->assertFalse($result['requiere_lectura']);
        $this->assertNull($result['leido']);
        $this->assertNull($result['vence']);
        $this->assertNull($result['vence_texto']);
    }

    public function test_one_no_account_sees_is_not_found_like_one_that_does_not_exist(): void
    {
        $other = Announcement::factory()->forCompanies($this->other)->create();
        $withdrawn = Announcement::factory()->withdrawn()->create();
        $scheduled = Announcement::factory()->scheduled()->create();

        $missing = $this->toolError('anuncio', ['id' => 999999]);

        $this->assertSame(Anuncio::NOT_FOUND, $missing);
        $this->assertSame($missing, $this->toolError('anuncio', ['id' => $other->id]));
        $this->assertSame($missing, $this->toolError('anuncio', ['id' => $withdrawn->id]));
        $this->assertSame($missing, $this->toolError('anuncio', ['id' => $scheduled->id]));
    }

    public function test_the_companies_never_include_one_outside_the_connection(): void
    {
        $announcement = Announcement::factory()->forCompanies($this->segurex, $this->other)->create();

        $this->assertSame(['Segurex'], $this->toolResult('anuncio', ['id' => $announcement->id])['anuncio']['empresas']);
    }

    public function test_after_unlinking_the_other_company_s_announcement_is_not_found(): void
    {
        $announcement = Announcement::factory()->forCompanies($this->derivados)->create();
        $this->toolResult('anuncio', ['id' => $announcement->id]);

        $this->unlinkTheLinkedAccount();

        $this->assertSame(Anuncio::NOT_FOUND, $this->toolError('anuncio', ['id' => $announcement->id]));
    }

    #[DataProvider('linkedAccountThatCanNoLongerConnect')]
    public function test_a_linked_account_that_can_no_longer_connect_is_not_read_for(string $case): void
    {
        $announcement = Announcement::factory()->forCompanies($this->derivados)->create();

        $this->makeLinkedAccountUnusable($case);

        $this->assertSame(Anuncio::NOT_FOUND, $this->toolError('anuncio', ['id' => $announcement->id]));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function badIds(): array
    {
        return [
            'missing' => [[]],
            'zero' => [['id' => 0]],
            'text' => [['id' => 'uno']],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('badIds')]
    public function test_a_bad_id_is_refused(array $arguments): void
    {
        $this->assertStringContainsString('id', $this->toolError('anuncio', $arguments));
    }
}
