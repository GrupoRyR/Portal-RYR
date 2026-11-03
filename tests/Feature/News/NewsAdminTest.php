<?php

namespace Tests\Feature\News;

use App\Audit\AuditPresenter;
use App\Livewire\Admin\NewsManager;
use App\Models\Company;
use App\Models\NewsItem;
use App\Models\NewsSource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class NewsAdminTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private Company $sdc;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->seed(RoleSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00', 'America/Bogota'));

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);
        $this->sdc = Company::factory()->create(['name' => 'Soluciones y Derivados', 'short_name' => 'SDC']);
        $this->admin = $this->userWith('super_admin', ['name' => 'Jhon Castro']);
    }

    private function userWith(string $role, array $attributes = []): User
    {
        $user = User::factory()->microsoft($this->segurex)->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    private function manager(): Testable
    {
        return Livewire::actingAs($this->admin)->test(NewsManager::class);
    }

    private function source(array $attributes = []): NewsSource
    {
        return NewsSource::factory()->for($this->segurex)->create([
            'name' => 'El Tiempo',
            'url' => 'https://www.eltiempo.com/rss/colombia.xml',
            ...$attributes,
        ]);
    }

    // --- Access -------------------------------------------------------------

    public function test_super_admins_open_the_page_from_the_sidebar(): void
    {
        $this->get(route('admin.news.index'))->assertRedirect(route('login'));

        $this->actingAs($this->admin)->get(route('admin.news.index'))
            ->assertOk()
            ->assertSee('href="'.route('admin.news.index').'" aria-current="page"', false)
            ->assertSeeText('Noticias')
            ->assertSeeText('Agregar fuente');
    }

    public function test_everyone_else_is_forbidden_and_sees_no_link(): void
    {
        $link = 'href="'.route('admin.news.index').'"';

        foreach (['portal_admin', 'announcements_editor'] as $role) {
            $user = $this->userWith($role);
            $this->actingAs($user)->get(route('admin.news.index'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.announcements.index'))->assertOk()->assertDontSee($link, false);
            Livewire::actingAs($user)->test(NewsManager::class)->assertForbidden();
        }

        $this->actingAs(User::factory()->create())->get(route('admin.news.index'))->assertForbidden();
    }

    /**
     * @return array<string, array{string, array<int, mixed>}>
     */
    public static function actions(): array
    {
        return [
            'create' => ['create', []],
            'edit' => ['startEditing', ['source']],
            'save' => ['save', []],
            'toggle' => ['toggleActive', ['source']],
            'delete' => ['delete', ['source']],
            'render' => ['$refresh', []],
        ];
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    #[DataProvider('actions')]
    public function test_every_action_checks_the_role_again(string $action, array $arguments): void
    {
        $source = $this->source();
        $arguments = array_map(fn ($argument) => $argument === 'source' ? $source->id : $argument, $arguments);

        $manager = $this->manager()
            ->set('name', 'Portafolio')
            ->set('url', 'https://www.portafolio.co/rss/economia.xml');
        $this->admin->removeRole('super_admin');

        $manager->call($action, ...$arguments)->assertForbidden();

        $this->assertSame(1, NewsSource::query()->count());
        $this->assertTrue($source->fresh()->active);
        $this->assertSame('El Tiempo', $source->fresh()->name);
    }

    // --- List ---------------------------------------------------------------

    public function test_the_list_shows_the_sources_of_the_selected_company_and_tab(): void
    {
        $this->source(['last_fetched_at' => now()->subMinutes(20)]);
        $this->source(['name' => 'Portafolio', 'url' => 'https://www.portafolio.co/rss.xml', 'last_error_code' => 'http_404', 'last_fetched_at' => now()]);
        $this->source(['name' => 'Reuters', 'tab' => NewsSource::TAB_INTERNATIONAL, 'url' => 'https://www.reuters.com/rss.xml']);
        $this->source(['name' => 'Apagada', 'active' => false, 'url' => 'https://www.apagada.com/rss.xml']);
        NewsSource::factory()->for($this->sdc)->create(['name' => 'Fuente de SDC']);

        $this->manager()
            ->assertSet('companyId', $this->segurex->id)
            ->assertSeeInOrder(['Colombia · 3', 'Internacional · 1', 'Sector · 0'])
            ->assertSeeText('El Tiempo')
            ->assertSeeText('https://www.eltiempo.com/rss/colombia.xml')
            ->assertSeeText('Portafolio')
            ->assertSeeText('El sitio respondió 404 (http_404)')
            ->assertSeeText('Inactiva')
            ->assertDontSeeText('Reuters')
            ->assertDontSeeText('Fuente de SDC')
            ->set('tab', NewsSource::TAB_INTERNATIONAL)
            ->assertSeeText('Reuters')
            ->assertDontSeeText('El Tiempo')
            ->set('companyId', $this->sdc->id)
            ->assertSeeText('No hay fuentes en esta pestaña.')
            ->set('tab', NewsSource::TAB_COLOMBIA)
            ->assertSeeText('Fuente de SDC');
    }

    public function test_an_unknown_company_or_tab_falls_back_to_the_first(): void
    {
        $this->manager()
            ->set('companyId', 999)
            ->set('tab', 'deportes')
            ->call('$refresh')
            ->assertSet('companyId', $this->segurex->id)
            ->assertSet('tab', NewsSource::TAB_COLOMBIA);
    }

    // --- Add, edit, deactivate, delete ---------------------------------------

    public function test_a_source_is_added_to_the_selected_company_and_tab_and_audited(): void
    {
        $this->manager()
            ->set('companyId', $this->sdc->id)
            ->set('tab', NewsSource::TAB_SECTOR)
            ->set('name', '  Seguridad al día ')
            ->set('url', ' https://www.seguridad.example.com/feed ')
            ->call('create')
            ->assertHasNoErrors()
            ->assertSet('name', '')
            ->assertSet('url', '')
            ->assertSeeText('Se agregó la fuente «Seguridad al día».');

        $source = NewsSource::query()->sole();
        $this->assertSame($this->sdc->id, $source->company_id);
        $this->assertSame(NewsSource::TAB_SECTOR, $source->tab);
        $this->assertSame('Seguridad al día', $source->name);
        $this->assertSame('https://www.seguridad.example.com/feed', $source->url);
        $this->assertTrue($source->active);
        Http::assertNothingSent();

        $entry = app(AuditPresenter::class)->activity(Activity::query()->where('log_name', NewsSource::AUDIT_LOG)->latest('id')->firstOrFail());
        $this->assertSame('Creó la fuente de noticias «Seguridad al día»', $entry->what);
        $this->assertSame('Jhon Castro', $entry->who);
        $labels = array_column($entry->changes, 'label');
        foreach (['Nombre', 'URL', 'Pestaña', 'Activa', 'Empresa'] as $label) {
            $this->assertContains($label, $labels);
        }
        $this->assertContains(['label' => 'Empresa', 'old' => '—', 'new' => 'SDC'], $entry->changes);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidUrls(): array
    {
        return [
            'http' => ['http://www.eltiempo.com/rss.xml', 'url'],
            'port' => ['https://www.eltiempo.com:8443/rss.xml', 'url'],
            'userinfo' => ['https://admin:secreto@www.eltiempo.com/rss.xml', 'url'],
            'ip' => ['https://169.254.169.254/latest/meta-data', 'url'],
            'localhost' => ['https://localhost/rss', 'url'],
            'empty' => ['', 'url'],
        ];
    }

    #[DataProvider('invalidUrls')]
    public function test_unsafe_urls_are_refused_when_saving(string $url, string $field): void
    {
        $this->manager()
            ->set('name', 'Fuente')
            ->set('url', $url)
            ->call('create')
            ->assertHasErrors([$field]);

        $this->assertSame(0, NewsSource::query()->count());
    }

    public function test_the_name_is_required_and_a_url_cannot_repeat_in_the_same_tab(): void
    {
        $this->source();

        $this->manager()
            ->set('name', '')
            ->set('url', 'https://www.otro.example.com/rss')
            ->call('create')
            ->assertHasErrors(['name' => 'required'])
            ->set('name', 'Repetida')
            ->set('url', 'https://www.eltiempo.com/rss/colombia.xml')
            ->call('create')
            ->assertHasErrors(['url'])
            ->set('tab', NewsSource::TAB_SECTOR)
            ->call('create')
            ->assertHasNoErrors();

        $this->assertSame(2, NewsSource::query()->count());
    }

    public function test_a_source_is_edited_and_its_changes_audited(): void
    {
        $source = $this->source(['last_error_code' => 'http_404']);

        $this->manager()
            ->call('startEditing', $source->id)
            ->assertSet('editingId', $source->id)
            ->assertSet('name', 'El Tiempo')
            ->assertSet('formTab', NewsSource::TAB_COLOMBIA)
            ->set('name', 'El Tiempo Colombia')
            ->set('url', 'https://www.eltiempo.com/rss/nacional.xml')
            ->set('formTab', NewsSource::TAB_INTERNATIONAL)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('editingId', null)
            ->assertSet('tab', NewsSource::TAB_INTERNATIONAL);

        $source->refresh();
        $this->assertSame('El Tiempo Colombia', $source->name);
        $this->assertSame('https://www.eltiempo.com/rss/nacional.xml', $source->url);
        $this->assertSame(NewsSource::TAB_INTERNATIONAL, $source->tab);
        $this->assertNull($source->last_error_code, 'A new URL starts without the old error.');

        $entry = app(AuditPresenter::class)->activity(Activity::query()->where('log_name', NewsSource::AUDIT_LOG)->latest('id')->firstOrFail());
        $this->assertSame('Cambió la fuente de noticias «El Tiempo Colombia»', $entry->what);
        $this->assertContains(['label' => 'Pestaña', 'old' => 'colombia', 'new' => 'internacional'], $entry->changes);
    }

    public function test_editing_validates_like_adding(): void
    {
        $source = $this->source();

        $this->manager()
            ->call('startEditing', $source->id)
            ->set('url', 'http://www.eltiempo.com/rss.xml')
            ->call('save')
            ->assertHasErrors(['url']);

        $this->assertSame('https://www.eltiempo.com/rss/colombia.xml', $source->fresh()->url);
    }

    public function test_a_source_is_deactivated_and_activated_again(): void
    {
        $source = $this->source();

        $manager = $this->manager()->call('toggleActive', $source->id)->assertSeeText('Se desactivó la fuente «El Tiempo».');
        $this->assertFalse($source->fresh()->active);

        $entry = app(AuditPresenter::class)->activity(Activity::query()->where('log_name', NewsSource::AUDIT_LOG)->latest('id')->firstOrFail());
        $this->assertSame([['label' => 'Activa', 'old' => 'Sí', 'new' => 'No']], $entry->changes);

        $manager->call('toggleActive', $source->id)->assertSeeText('Se activó la fuente «El Tiempo».');
        $this->assertTrue($source->fresh()->active);
    }

    public function test_deleting_a_source_removes_its_items_and_is_audited(): void
    {
        $source = $this->source();
        NewsItem::factory()->count(3)->for($source, 'source')->create();

        $this->manager()
            ->call('delete', $source->id)
            ->assertSeeText('Se borró la fuente «El Tiempo».');

        $this->assertModelMissing($source);
        $this->assertSame(0, NewsItem::query()->count());

        $entry = app(AuditPresenter::class)->activity(Activity::query()->where('log_name', NewsSource::AUDIT_LOG)->latest('id')->firstOrFail());
        $this->assertSame('Borró la fuente de noticias «El Tiempo»', $entry->what);
        $this->assertContains(['label' => 'Empresa', 'old' => 'Segurex', 'new' => '—'], $entry->changes);
    }

    public function test_the_fetch_bookkeeping_is_not_audited(): void
    {
        $source = $this->source();
        $before = Activity::query()->count();

        $source->forceFill(['last_fetched_at' => now(), 'last_error_code' => 'timeout'])->save();

        $this->assertSame($before, Activity::query()->count());
    }
}
