<?php

namespace Tests\Feature\Directory;

use App\Models\Company;
use App\Models\DirectoryEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * /personas: the directory of the viewer's own company, copied from
 * Microsoft (odd/tasks/personas.md WU1).
 */
class PeoplePageTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam']);
        $this->ana = User::factory()->microsoft($this->segurex)->create(['name' => 'Ana María Pérez']);
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get(route('people.index'))->assertRedirect(route('login'));
        $this->assertSame(url('/personas'), route('people.index'));
    }

    public function test_it_lists_only_the_viewers_company_with_ways_to_reach_each_person(): void
    {
        $luis = DirectoryEntry::factory()->forCompany($this->segurex)->create([
            'display_name' => 'Luis Gómez',
            'job_title' => 'Analista de cartera',
            'department' => 'Finanzas',
            'office_location' => 'Bogotá · Piso 3',
            'business_phone' => '+57 (601) 555-0101 ext. 12',
            'email' => 'luis.gomez@segurex.com',
        ]);
        DirectoryEntry::factory()->forCompany($this->segurex)->create(['display_name' => 'Zoe Ruiz', 'job_title' => null, 'department' => 'Comercial', 'business_phone' => null]);
        // Another company sharing the tenant, and one of another tenant.
        $derivados = Company::factory()->forTenant($this->segurex->tenant_id, ['derivados.com'], primary: false)->create();
        DirectoryEntry::factory()->forCompany($derivados)->create(['display_name' => 'Persona de Derivados']);
        DirectoryEntry::factory()->forCompany(Company::factory()->create())->create(['display_name' => 'Persona de otra empresa']);

        $this->page()
            ->assertSeeTextInOrder(['Personas', 'Luis Gómez', 'Analista de cartera · Finanzas', 'Bogotá · Piso 3', '+57 (601) 555-0101 ext. 12', 'luis.gomez@segurex.com', 'Chat en Teams', 'Zoe Ruiz', 'Comercial'])
            ->assertSee('href="tel:+57601555010112"', false)
            ->assertSee('href="mailto:luis.gomez@segurex.com"', false)
            ->assertSee('href="https://teams.microsoft.com/l/chat/0/0?users=luis.gomez%40segurex.com" target="_blank" rel="noopener"', false)
            ->assertSee('id="p-'.$luis->id.'"', false)
            ->assertDontSeeText('Persona de Derivados')
            ->assertDontSeeText('Persona de otra empresa')
            ->assertDontSee('livewire', false)
            ->assertDontSee('x-data', false);
    }

    /**
     * `?` is valid in the local part of an address, but in a `mailto:` it
     * would start headers (cc, subject, body): it is percent-encoded.
     */
    public function test_the_mail_link_never_carries_extra_headers(): void
    {
        DirectoryEntry::factory()->forCompany($this->segurex)->create(['email' => 'x?cc=evil&body=hola@segurex.com']);

        $this->page()
            ->assertSee('href="mailto:x%3Fcc%3Devil%26body%3Dhola@segurex.com"', false)
            ->assertDontSee('mailto:x?cc', false);
    }

    public function test_the_search_looks_through_name_title_area_office_and_email_ignoring_accents(): void
    {
        DirectoryEntry::factory()->forCompany($this->segurex)->create(['display_name' => 'Luis Gómez', 'job_title' => 'Analista', 'department' => 'Finanzas', 'office_location' => 'Medellín', 'email' => 'lgomez@segurex.com']);
        DirectoryEntry::factory()->forCompany($this->segurex)->create(['display_name' => 'Marta Díaz', 'job_title' => 'Coordinadora', 'department' => 'Operaciones', 'office_location' => 'Bogotá', 'email' => 'mdiaz@segurex.com']);
        DirectoryEntry::factory()->forCompany(Company::factory()->create())->create(['display_name' => 'Luis Otro', 'department' => 'Finanzas']);

        foreach (['gomez' => 'Luis Gómez', 'ANALISTA' => 'Luis Gómez', 'finanzas luis' => 'Luis Gómez', 'medellin' => 'Luis Gómez', 'mdiaz@' => 'Marta Díaz', 'Operaciónes' => 'Marta Díaz'] as $query => $found) {
            $response = $this->page('?q='.urlencode($query))->assertSeeText($found);
            $other = $found === 'Luis Gómez' ? 'Marta Díaz' : 'Luis Gómez';
            $response->assertDontSeeText($other)->assertDontSeeText('Luis Otro');
        }

        $this->page('?q=%25')
            ->assertSeeText('No encontramos personas para «%».')
            ->assertDontSeeText('Luis Gómez');
        $this->page('?q=gomez')->assertSee('value="gomez"', false)->assertSeeText('1 persona');
    }

    public function test_it_shows_fifty_people_a_page_keeping_the_search(): void
    {
        DirectoryEntry::factory()->forCompany($this->segurex)->count(51)->sequence(fn ($sequence) => [
            'display_name' => sprintf('Persona %02d', $sequence->index + 1),
            'department' => 'Finanzas',
        ])->create();

        $this->page('?q=finanzas')
            ->assertSeeText('Persona 50')
            ->assertDontSeeText('Persona 51')
            ->assertSeeText('Página 1 de 2')
            ->assertSee('q=finanzas&amp;page=2', false);

        $this->page('?q=finanzas&page=2')
            ->assertSeeText('Persona 51')
            ->assertDontSeeText('Persona 50');
    }

    public function test_accounts_without_a_directory_get_an_empty_page_and_no_ones_data(): void
    {
        DirectoryEntry::factory()->forCompany($this->segurex)->create(['display_name' => 'Luis Gómez']);
        $inactive = Company::factory()->inactive()->create();
        DirectoryEntry::factory()->forCompany($inactive)->create(['display_name' => 'Persona inactiva']);

        $viewers = [
            'emergency' => User::factory()->emergency()->create(),
            'without company' => User::factory()->create(['company_id' => null]),
            'guest (B2B)' => User::factory()->microsoft($this->segurex)->create(['is_guest' => true]),
            'inactive company' => User::factory()->microsoft($inactive)->create(),
        ];

        foreach ($viewers as $label => $viewer) {
            $this->actingAs($viewer)->get(route('people.index', ['q' => 'a']))
                ->assertOk()
                ->assertSeeText('El directorio de personas no está disponible para esta cuenta.')
                ->assertDontSeeText('Luis Gómez')
                ->assertDontSeeText('Persona inactiva');
        }
    }

    public function test_an_unknown_guest_flag_still_counts_as_a_member(): void
    {
        DirectoryEntry::factory()->forCompany($this->segurex)->create(['display_name' => 'Luis Gómez']);
        $this->ana->forceFill(['is_guest' => null])->save();

        $this->page()->assertSeeText('Luis Gómez');
    }

    public function test_it_says_when_the_directory_has_not_been_copied_yet(): void
    {
        $this->page()->assertSeeText('El directorio de Segurex Latam aún no se ha copiado desde Microsoft.');
    }

    public function test_the_home_links_to_the_directory_only_for_accounts_with_one(): void
    {
        $this->page('', '/')->assertSee('href="'.route('people.index').'"', false)->assertSeeText('Ver directorio');

        $this->actingAs(User::factory()->emergency()->create())->get('/')
            ->assertOk()
            ->assertDontSee('href="'.route('people.index').'"', false);
    }

    private function page(string $suffix = '', string $path = '/personas'): TestResponse
    {
        return $this->actingAs($this->ana->fresh())->get($path.$suffix)->assertOk();
    }
}
