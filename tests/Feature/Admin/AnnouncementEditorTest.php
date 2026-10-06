<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\AnnouncementEditor;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AnnouncementEditorTest extends TestCase
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

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);
        $this->derivados = Company::factory()->create(['name' => 'Soluciones y Derivados', 'short_name' => 'Derivados']);

        $this->admin = User::factory()->create(['name' => 'Jhon Castro']);
        $this->admin->assignRole('portal_admin');

        $this->editor = User::factory()->microsoft($this->segurex)->create();
        $this->editor->assignRole('announcements_editor');
    }

    // --- Pages ------------------------------------------------------------

    public function test_the_editor_pages_follow_the_policy(): void
    {
        $mine = Announcement::factory()->forCompanies($this->segurex)->create(['title' => 'De Segurex']);
        $all = Announcement::factory()->create(['title' => 'Para todas']);

        $this->actingAs($this->admin)->get(route('admin.announcements.create'))->assertOk()->assertSeeText('Nuevo anuncio');
        $this->actingAs($this->admin)->get(route('admin.announcements.edit', $all))->assertOk()->assertSeeText('Editar anuncio');

        $this->actingAs($this->editor)->get(route('admin.announcements.create'))->assertOk();
        $this->actingAs($this->editor)->get(route('admin.announcements.edit', $mine))->assertOk();
        $this->actingAs($this->editor)->get(route('admin.announcements.edit', $all))->assertForbidden();

        $nobody = User::factory()->microsoft($this->segurex)->create();
        $this->actingAs($nobody)->get(route('admin.announcements.edit', $mine))->assertForbidden();
    }

    // --- Create and edit --------------------------------------------------

    public function test_an_admin_creates_an_announcement_for_every_company(): void
    {
        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)
            ->assertSet('audience', Announcement::AUDIENCE_ALL)
            ->assertSet('startsAt', '2026-10-06T10:00')
            ->set('title', '  Ya puedes entrar con tu cuenta de Microsoft  ')
            ->set('body', "Toma Pedidos SAP ya no pide otra contraseña.\nLas demás apps se irán sumando.")
            ->set('startsAt', '2026-10-06T08:00')
            ->set('endsAt', '2026-10-20T18:00')
            ->set('isPinned', true)
            ->set('image', UploadedFile::fake()->image('foto.jpg', 1600, 900))
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.announcements.edit', Announcement::query()->sole()));

        $announcement = Announcement::query()->sole();
        $this->assertSame('Ya puedes entrar con tu cuenta de Microsoft', $announcement->title);
        $this->assertSame("Toma Pedidos SAP ya no pide otra contraseña.\nLas demás apps se irán sumando.", $announcement->body);
        $this->assertSame(Announcement::AUDIENCE_ALL, $announcement->audience);
        $this->assertSame('2026-10-06 08:00', $announcement->starts_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-20 18:00', $announcement->ends_at->format('Y-m-d H:i'));
        $this->assertTrue($announcement->is_pinned);
        $this->assertTrue($announcement->author->is($this->admin));
        $this->assertStringStartsWith('announcements/'.$announcement->id.'/', $announcement->image_path);
        Storage::disk('public')->assertExists($announcement->image_path);
        $this->assertSame(Announcement::STATUS_PUBLISHED, $announcement->status);
    }

    public function test_an_admin_targets_specific_companies(): void
    {
        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)
            ->set('title', 'Solo para Derivados')
            ->set('body', 'Texto')
            ->set('audience', Announcement::AUDIENCE_COMPANIES)
            ->set('companyIds', [(string) $this->derivados->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([$this->derivados->id], Announcement::query()->sole()->companies()->pluck('companies.id')->all());
    }

    public function test_editing_keeps_the_author_and_updates_the_fields(): void
    {
        $announcement = Announcement::factory()->forCompanies($this->segurex)->create([
            'title' => 'Antes',
            'author_id' => $this->editor->id,
        ]);

        $this->editorFor($announcement, $this->admin)
            ->assertSet('title', 'Antes')
            ->assertSet('audience', Announcement::AUDIENCE_COMPANIES)
            ->assertSet('companyIds', [(string) $this->segurex->id])
            ->set('title', 'Después')
            ->set('audience', Announcement::AUDIENCE_ALL)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.announcements.edit', $announcement));

        $announcement->refresh();
        $this->assertSame('Después', $announcement->title);
        $this->assertSame(Announcement::AUDIENCE_ALL, $announcement->audience);
        $this->assertSame(0, $announcement->companies()->count());
        $this->assertTrue($announcement->author->is($this->editor));
    }

    public function test_the_image_can_be_removed(): void
    {
        $announcement = Announcement::factory()->create();
        $this->editorFor($announcement, $this->admin)->set('image', UploadedFile::fake()->image('a.png', 1280, 720))->call('save');
        $path = $announcement->fresh()->image_path;
        Storage::disk('public')->assertExists($path);

        $this->editorFor($announcement->fresh(), $this->admin)
            ->call('clearImage')
            ->assertSet('removeImage', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($announcement->fresh()->image_path);
        Storage::disk('public')->assertMissing($path);
    }

    // --- Validation -------------------------------------------------------

    public function test_title_and_body_are_required_and_limited(): void
    {
        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)
            ->set('title', '')
            ->set('body', '')
            ->call('save')
            ->assertHasErrors(['title' => 'required', 'body' => 'required']);

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)
            ->set('title', str_repeat('a', 121))
            ->set('body', str_repeat('ñ', 281))
            ->call('save')
            ->assertHasErrors(['title' => 'max', 'body' => 'max']);

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)
            ->set('title', str_repeat('a', 120))
            ->set('body', str_repeat('ñ', 280))
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_the_end_must_come_after_the_start(): void
    {
        $editor = Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)
            ->set('title', 'Título')
            ->set('body', 'Texto')
            ->set('startsAt', '2026-10-10T08:00');

        $editor->set('endsAt', '2026-10-10T08:00')->call('save')->assertHasErrors(['endsAt' => 'after']);
        $editor->set('endsAt', '2026-10-09T08:00')->call('save')->assertHasErrors(['endsAt' => 'after']);
        $editor->set('startsAt', 'mañana')->call('save')->assertHasErrors(['startsAt']);
        $editor->set('startsAt', '')->call('save')->assertHasErrors(['startsAt' => 'required']);

        $this->assertSame(0, Announcement::query()->count());
    }

    public function test_specific_companies_need_at_least_one_existing_company(): void
    {
        $editor = Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)
            ->set('title', 'Título')
            ->set('body', 'Texto')
            ->set('audience', Announcement::AUDIENCE_COMPANIES);

        $editor->set('companyIds', [])->call('save')->assertHasErrors(['companyIds' => 'required']);
        $editor->set('companyIds', ['999999'])->call('save')->assertHasErrors(['companyIds.0' => 'exists']);
        $editor->set('audience', 'todo')->call('save')->assertHasErrors(['audience' => 'in']);

        $this->assertSame(0, Announcement::query()->count());
    }

    public function test_svg_and_oversized_images_are_rejected(): void
    {
        $base = fn () => Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)->set('title', 'T')->set('body', 'B');

        $base()->set('image', UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'))
            ->call('save')->assertHasErrors('image');
        $base()->set('image', UploadedFile::fake()->image('grande.png', 100, 100)->size(2049))
            ->call('save')->assertHasErrors('image');

        $this->assertSame(0, Announcement::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles('announcements'));
    }

    // --- Editor restrictions (server side) --------------------------------

    public function test_an_editor_creates_announcements_for_their_own_company_only(): void
    {
        Livewire::actingAs($this->editor)->test(AnnouncementEditor::class)
            ->assertSet('audience', Announcement::AUDIENCE_COMPANIES)
            ->assertSet('companyIds', [(string) $this->segurex->id])
            ->assertSeeText('Segurex')
            ->assertDontSeeText('Derivados')
            ->set('title', 'Para Segurex')
            ->set('body', 'Texto')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([$this->segurex->id], Announcement::query()->sole()->companies()->pluck('companies.id')->all());
    }

    public function test_an_editor_cannot_publish_to_every_company_even_with_a_crafted_request(): void
    {
        Livewire::actingAs($this->editor)->test(AnnouncementEditor::class)
            ->set('title', 'Para todas')
            ->set('body', 'Texto')
            ->set('audience', Announcement::AUDIENCE_ALL)
            ->call('save')
            ->assertForbidden();

        $this->assertSame(0, Announcement::query()->count());
    }

    public function test_an_editor_cannot_add_another_company_even_with_a_crafted_request(): void
    {
        $announcement = Announcement::factory()->forCompanies($this->segurex)->create(['title' => 'Antes']);

        $this->editorFor($announcement, $this->editor)
            ->set('title', 'Después')
            ->set('companyIds', [(string) $this->segurex->id, (string) $this->derivados->id])
            ->call('save')
            ->assertForbidden();

        $this->editorFor($announcement, $this->editor)
            ->set('companyIds', [(string) $this->derivados->id])
            ->call('save')
            ->assertForbidden();

        $this->assertSame('Antes', $announcement->fresh()->title);
        $this->assertSame([$this->segurex->id], $announcement->companies()->pluck('companies.id')->all());
    }

    public function test_an_editor_cannot_open_or_change_another_company_announcement(): void
    {
        $theirs = Announcement::factory()->forCompanies($this->derivados)->create();

        Livewire::actingAs($this->editor)->test(AnnouncementEditor::class, ['announcement' => $theirs])->assertForbidden();
    }

    // --- Withdraw and republish ---------------------------------------------

    public function test_withdrawing_and_republishing(): void
    {
        $announcement = Announcement::factory()->forCompanies($this->segurex)->create();

        $this->editorFor($announcement, $this->editor)
            ->assertSeeText('Publicado')
            ->assertSee('wire:confirm', false)
            ->call('withdraw')
            ->assertSeeText('Retirado')
            ->assertSeeText('Volver a publicar');

        $this->assertSame(Announcement::STATUS_WITHDRAWN, $announcement->fresh()->status);

        $this->editorFor($announcement->fresh(), $this->editor)
            ->call('republish')
            ->assertSeeText('Retirar');

        $this->assertSame(Announcement::STATUS_PUBLISHED, $announcement->fresh()->status);
    }

    public function test_people_who_may_not_edit_cannot_withdraw(): void
    {
        $all = Announcement::factory()->create();
        $component = $this->editorFor($all, $this->admin);

        $this->admin->syncRoles([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $component->call('withdraw')->assertForbidden();
        $this->assertNull($all->fresh()->withdrawn_at);
    }

    // --- Counter and preview ------------------------------------------------

    public function test_the_body_counter_follows_the_text(): void
    {
        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)
            ->assertSeeText('0 / 280')
            ->set('body', 'Año nuevo')
            ->assertSeeText('9 / 280');
    }

    public function test_the_preview_uses_the_home_card_and_banner_with_escaped_text(): void
    {
        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)
            ->set('title', '<script>alert(1)</script>')
            ->set('body', '<b>Hola</b>')
            ->set('isPinned', true)
            ->assertSee('data-announcement-card', false)
            ->assertSee('data-announcement-strip', false)
            ->assertSeeText('Escritorio')
            ->assertSeeText('Celular')
            ->assertSeeText('Fijado')
            ->assertSeeText('Anuncio fijado')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<b>Hola</b>', false);
    }

    public function test_the_preview_shows_the_new_image_before_saving(): void
    {
        $component = Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)
            ->set('image', UploadedFile::fake()->image('foto.png', 1600, 900));

        $this->assertStringContainsString('preview', $component->instance()->previewCard()->imageUrl ?? '');
    }

    // --- Audit ----------------------------------------------------------------

    public function test_every_change_is_recorded_with_its_causer(): void
    {
        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)
            ->set('title', 'Uno')
            ->set('body', 'Texto')
            ->set('audience', Announcement::AUDIENCE_COMPANIES)
            ->set('companyIds', [(string) $this->segurex->id])
            ->call('save');

        $announcement = Announcement::query()->sole();
        $this->editorFor($announcement, $this->admin)->set('title', 'Dos')->call('save');
        $this->editorFor($announcement->fresh(), $this->admin)->call('withdraw');

        $entries = Activity::query()->where('log_name', Announcement::AUDIT_LOG)->orderBy('id')->get();

        $this->assertSame(
            ['created', 'announcement.companies_changed', 'updated', 'announcement.withdrawn'],
            $entries->pluck('event')->all(),
        );
        $this->assertSame(['companies' => ['Segurex']], $entries[1]->properties['attributes']);
        $this->assertSame(['title' => 'Dos'], $entries[2]->properties['attributes']);
        $this->assertTrue($entries->every(fn (Activity $entry) => $entry->causer?->is($this->admin)));
    }

    private function editorFor(Announcement $announcement, User $user): Testable
    {
        return Livewire::actingAs($user)->test(AnnouncementEditor::class, ['announcement' => $announcement]);
    }
}
