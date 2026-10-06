<?php

namespace Tests\Feature\Admin;

use App\Models\Announcement;
use App\Models\App;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AnnouncementListTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private Company $derivados;

    private User $admin;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('public');
        $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00', 'America/Bogota'));

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex', 'slug' => 'segurex']);
        $this->derivados = Company::factory()->create(['name' => 'Soluciones y Derivados', 'short_name' => 'Derivados', 'slug' => 'derivados']);

        $this->admin = User::factory()->create(['name' => 'Jhon Castro']);
        $this->admin->assignRole('portal_admin');

        $this->editor = User::factory()->microsoft($this->segurex)->create(['name' => 'Leonardo Herrera']);
        $this->editor->assignRole('announcements_editor');
    }

    // --- Access and navigation --------------------------------------------

    public function test_announcement_editors_land_on_the_announcements(): void
    {
        $this->actingAs($this->editor)->get('/admin')->assertRedirect(route('admin.announcements.index'));
    }

    public function test_admins_keep_their_landing_pages(): void
    {
        $this->actingAs($this->admin)->get('/admin')->assertRedirect(route('admin.apps.index'));

        $super = User::factory()->create();
        $super->assignRole(['super_admin', 'announcements_editor']);
        $this->actingAs($super)->get('/admin')->assertRedirect(route('admin.companies.index'));
    }

    public function test_editors_see_only_announcements_in_the_sidebar(): void
    {
        $response = $this->actingAs($this->editor)->get(route('admin.announcements.index'))->assertOk();

        $sidebar = $this->sidebar($response->getContent());

        $this->assertStringContainsString('href="'.route('admin.announcements.index').'" aria-current="page"', $sidebar);
        foreach (['Aplicaciones', 'Categorías', 'Empresas', 'Roles', 'Integraciones', 'Auditoría', 'Informe de uso', 'Configuración', 'Próximamente', 'Catálogo', 'Organización'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $sidebar, $hidden);
        }
        $this->assertStringContainsString('Editor de anuncios', $sidebar);
        $this->assertStringContainsString('Volver al portal', $sidebar);
    }

    public function test_admins_get_announcements_as_a_link_among_the_other_sections(): void
    {
        $sidebar = $this->sidebar($this->actingAs($this->admin)->get(route('admin.apps.index'))->getContent());

        $this->assertStringContainsString('href="'.route('admin.announcements.index').'"', $sidebar);
        $this->assertStringContainsString('Aplicaciones', $sidebar);
        $this->assertStringContainsString('Categorías', $sidebar);
    }

    public function test_editors_cannot_open_other_sections(): void
    {
        $app = App::factory()->create();

        $this->actingAs($this->editor);
        $this->get(route('admin.apps.index'))->assertForbidden();
        $this->get(route('admin.apps.create'))->assertForbidden();
        $this->get(route('admin.apps.edit', $app))->assertForbidden();
        $this->get(route('admin.categories.index'))->assertForbidden();
        $this->get(route('admin.companies.index'))->assertForbidden();
        $this->get(route('admin.companies.show', $this->segurex))->assertForbidden();
    }

    public function test_people_without_a_role_are_still_forbidden(): void
    {
        $this->actingAs(User::factory()->microsoft($this->segurex)->create());

        $this->get('/admin')->assertForbidden();
        $this->get(route('admin.announcements.index'))->assertForbidden();
        $this->get(route('admin.announcements.create'))->assertForbidden();
    }

    public function test_editors_get_the_administration_link_on_the_home_screen(): void
    {
        $this->actingAs($this->editor)->get('/')->assertSee('href="'.route('admin.index').'"', false);
    }

    // --- List -------------------------------------------------------------

    public function test_the_list_shows_each_announcement_with_audience_dates_author_and_status(): void
    {
        Announcement::factory()->pinned()->forCompanies($this->segurex)->create([
            'title' => 'Ya puedes entrar con tu cuenta de Microsoft',
            'starts_at' => CarbonImmutable::parse('2026-10-01 08:00', 'America/Bogota'),
            'ends_at' => CarbonImmutable::parse('2026-10-20 18:00', 'America/Bogota'),
            'author_id' => $this->admin->id,
            'image_path' => 'announcements/1/a.webp',
        ]);
        Announcement::factory()->scheduled()->create([
            'title' => 'Mantenimiento programado de SAP',
            'starts_at' => CarbonImmutable::parse('2026-10-09 07:00', 'America/Bogota'),
        ]);
        Announcement::factory()->expired()->forCompanies($this->derivados)->create([
            'title' => 'Anuncio anterior',
            'ends_at' => CarbonImmutable::parse('2026-10-02 12:00', 'America/Bogota'),
        ]);

        $this->actingAs($this->admin)->get(route('admin.announcements.index'))
            ->assertOk()
            ->assertSeeText('Anuncios')
            ->assertSee('href="'.route('admin.announcements.create').'"', false)
            ->assertSeeText('Nuevo anuncio')
            ->assertSeeText('Ya puedes entrar con tu cuenta de Microsoft')
            ->assertSeeText('Segurex · 1 de octubre – 20 de octubre · por Jhon Castro')
            ->assertSee('aria-label="Fijado"', false)
            ->assertSee('src="'.Storage::disk('public')->url('announcements/1/a.webp').'"', false)
            ->assertSeeText('Todas las empresas · empieza 9 de octubre')
            ->assertSeeText('Derivados · terminó 2 de octubre')
            ->assertSeeTextInOrder(['Publicado', 'Programado', 'Vencido']);
    }

    public function test_the_tabs_count_each_status_and_filter_the_list(): void
    {
        Announcement::factory()->create(['title' => 'Uno publicado']);
        Announcement::factory()->create(['title' => 'Otro publicado']);
        Announcement::factory()->scheduled()->create(['title' => 'El programado']);
        Announcement::factory()->expired()->create(['title' => 'El vencido']);
        Announcement::factory()->withdrawn()->create(['title' => 'El retirado']);

        $this->actingAs($this->admin)->get(route('admin.announcements.index'))
            ->assertSeeTextInOrder(['Todos · 5', 'Publicados · 2', 'Programados · 1', 'Vencidos · 1', 'Retirados · 1'])
            ->assertSee('href="'.route('admin.announcements.index').'" aria-current="page"', false);

        $this->actingAs($this->admin)->get(route('admin.announcements.index', ['estado' => 'programado']))
            ->assertSeeText('El programado')
            ->assertDontSeeText('Uno publicado')
            ->assertDontSeeText('El retirado')
            ->assertSee('href="'.route('admin.announcements.index', ['estado' => 'programado']).'" aria-current="page"', false);

        $this->actingAs($this->admin)->get(route('admin.announcements.index', ['estado' => 'retirado']))
            ->assertSeeText('El retirado')
            ->assertSeeText('Retirado')
            ->assertDontSeeText('El programado');
    }

    public function test_the_company_filter_keeps_the_tab_and_includes_announcements_for_everyone(): void
    {
        Announcement::factory()->create(['title' => 'Para todas']);
        Announcement::factory()->forCompanies($this->segurex)->create(['title' => 'Solo Segurex']);
        Announcement::factory()->forCompanies($this->derivados)->create(['title' => 'Solo Derivados']);
        Announcement::factory()->scheduled()->forCompanies($this->segurex)->create(['title' => 'Segurex luego']);

        $this->actingAs($this->admin)->get(route('admin.announcements.index', ['empresa' => 'segurex']))
            ->assertSeeText('Para todas')
            ->assertSeeText('Solo Segurex')
            ->assertSeeText('Segurex luego')
            ->assertDontSeeText('Solo Derivados')
            ->assertSeeText('Todos · 3')
            ->assertSee('<option value="segurex" selected>', false)
            ->assertSee('href="'.e(route('admin.announcements.index', ['empresa' => 'segurex', 'estado' => 'programado'])).'"', false);

        $this->actingAs($this->admin)->get(route('admin.announcements.index', ['empresa' => 'segurex', 'estado' => 'programado']))
            ->assertSeeText('Segurex luego')
            ->assertDontSeeText('Solo Segurex');
    }

    public function test_unknown_filters_are_ignored(): void
    {
        Announcement::factory()->create(['title' => 'Visible']);

        $this->actingAs($this->admin)->get('/admin/anuncios?estado[]=x&empresa=nope')
            ->assertOk()
            ->assertSeeText('Visible')
            ->assertSeeText('Todos · 1');
    }

    public function test_editors_only_list_their_company_announcements_and_get_no_company_filter(): void
    {
        Announcement::factory()->forCompanies($this->segurex)->create(['title' => 'De Segurex']);
        Announcement::factory()->create(['title' => 'Para todas']);
        Announcement::factory()->forCompanies($this->derivados)->create(['title' => 'De Derivados']);

        $this->actingAs($this->editor)->get(route('admin.announcements.index', ['empresa' => 'derivados']))
            ->assertOk()
            ->assertSeeText('De Segurex')
            ->assertDontSeeText('Para todas')
            ->assertDontSeeText('De Derivados')
            ->assertSeeText('Todos · 1')
            ->assertDontSee('name="empresa"', false);
    }

    public function test_an_empty_list_says_so(): void
    {
        $this->actingAs($this->admin)->get(route('admin.announcements.index'))
            ->assertSeeText('Todavía no hay anuncios.');

        $this->actingAs($this->admin)->get(route('admin.announcements.index', ['estado' => 'vencido']))
            ->assertSeeText('No hay anuncios con este estado.');
    }

    private function sidebar(string $html): string
    {
        $start = strpos($html, 'id="admin-sidebar"');
        $this->assertNotFalse($start);

        return substr($html, $start, strpos($html, '</nav>', $start) - $start);
    }
}
