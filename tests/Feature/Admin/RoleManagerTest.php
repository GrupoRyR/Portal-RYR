<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\RoleManager;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class RoleManagerTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);
        $this->admin = User::factory()->microsoft($this->segurex)->create(['name' => 'Jhon Castro']);
        $this->admin->assignRole('super_admin');
    }

    private function person(string $name, string $email): User
    {
        return User::factory()->microsoft($this->segurex)->create(['name' => $name, 'email' => $email]);
    }

    // --- Search -------------------------------------------------------------

    public function test_the_search_needs_two_characters(): void
    {
        $this->person('Ana Pérez', 'ana@segurex.com');

        Livewire::actingAs($this->admin)->test(RoleManager::class)
            ->call('startAdding')
            ->set('search', 'a')
            ->assertDontSeeText('ana@segurex.com')
            ->set('search', 'an')
            ->assertSeeText('Ana Pérez')
            ->assertSeeText('ana@segurex.com')
            ->assertSeeText('Segurex');
    }

    public function test_the_search_matches_name_or_email_and_returns_at_most_eight(): void
    {
        foreach (range(1, 10) as $i) {
            $this->person("Persona {$i}", "persona{$i}@segurex.com");
        }
        $this->person('Luis Mora', 'lmora@derivados.com');

        $component = Livewire::actingAs($this->admin)->test(RoleManager::class)->call('startAdding')->set('search', 'persona');
        $this->assertCount(8, $component->instance()->results());

        $component->set('search', 'DERIVADOS')->assertSeeText('Luis Mora');
        $component->set('search', 'mora')->assertSeeText('lmora@derivados.com');
    }

    public function test_the_search_never_offers_emergency_accounts_or_current_editors(): void
    {
        User::factory()->emergency()->create(['name' => 'Cuenta de emergencia', 'email' => 'emergencia@segurex.com']);
        $editor = $this->person('Paula Emerson', 'paula@segurex.com');
        $editor->assignRole('announcements_editor');

        $component = Livewire::actingAs($this->admin)->test(RoleManager::class)->call('startAdding')->set('search', 'emer');

        $this->assertSame([], $component->instance()->results()->pluck('email')->all());
    }

    public function test_like_wildcards_are_matched_literally(): void
    {
        $this->person('Ana Pérez', 'ana@segurex.com');

        $component = Livewire::actingAs($this->admin)->test(RoleManager::class)->call('startAdding')->set('search', '%%');

        $this->assertCount(0, $component->instance()->results());
    }

    // --- Add and remove ---------------------------------------------------

    public function test_super_and_portal_admins_add_and_remove_editors(): void
    {
        $portalAdmin = $this->person('Leonardo Herrera', 'leonardo@segurex.com');
        $portalAdmin->assignRole('portal_admin');

        foreach ([$this->admin, $portalAdmin] as $actor) {
            $target = $this->person('Paula Gómez '.$actor->id, "paula{$actor->id}@segurex.com");

            Livewire::actingAs($actor)->test(RoleManager::class)
                ->call('addEditor', $target->id)
                ->assertHasNoErrors()
                ->assertSeeText("Paula Gómez {$actor->id}");

            $this->assertTrue($target->fresh()->hasRole('announcements_editor'));

            Livewire::actingAs($actor)->test(RoleManager::class)
                ->call('removeEditor', $target->id)
                ->assertSeeText("Paula Gómez {$actor->id} ya no es editor de anuncios.");

            $this->assertFalse($target->fresh()->hasRole('announcements_editor'));
        }
    }

    public function test_an_editor_cannot_add_or_remove_editors_with_a_crafted_call(): void
    {
        $editor = $this->person('Paula Gómez', 'paula@segurex.com');
        $editor->assignRole('announcements_editor');
        $target = $this->person('Ana Pérez', 'ana@segurex.com');

        // Mounting is already forbidden.
        Livewire::actingAs($editor)->test(RoleManager::class)->assertForbidden();

        // A snapshot taken by an admin and replayed by the editor.
        foreach ([['addEditor', $target->id], ['removeEditor', $editor->id]] as [$method, $id]) {
            $component = Livewire::actingAs($this->admin)->test(RoleManager::class);
            $this->actingAs($editor);
            $component->call($method, $id)->assertForbidden();
        }

        $this->assertFalse($target->fresh()->hasRole('announcements_editor'));
        $this->assertTrue($editor->fresh()->hasRole('announcements_editor'));
    }

    public function test_an_emergency_account_cannot_be_made_an_editor(): void
    {
        $emergency = User::factory()->emergency()->create();

        Livewire::actingAs($this->admin)->test(RoleManager::class)
            ->call('addEditor', $emergency->id)
            ->assertForbidden();

        $this->assertFalse($emergency->fresh()->hasRole('announcements_editor'));
    }

    public function test_adding_twice_or_removing_a_non_editor_changes_nothing(): void
    {
        $target = $this->person('Ana Pérez', 'ana@segurex.com');
        $target->assignRole('announcements_editor');

        Livewire::actingAs($this->admin)->test(RoleManager::class)
            ->call('addEditor', $target->id)
            ->call('removeEditor', $this->admin->id);

        $this->assertTrue($target->fresh()->hasRole('announcements_editor'));
        $this->assertTrue($this->admin->fresh()->hasRole('super_admin'));
        $this->assertSame(0, Activity::query()->where('log_name', 'roles')->count());
    }

    // --- Audit -------------------------------------------------------------

    public function test_assignments_and_removals_are_audited(): void
    {
        $target = $this->person('Ana Pérez', 'ana@segurex.com');

        Livewire::actingAs($this->admin)->test(RoleManager::class)
            ->call('addEditor', $target->id)
            ->call('removeEditor', $target->id);

        $entries = Activity::query()->where('log_name', 'roles')->orderBy('id')->get();

        $this->assertSame(['role.assigned', 'role.removed'], $entries->pluck('event')->all());
        foreach ($entries as $entry) {
            $this->assertTrue($entry->causer->is($this->admin));
            $this->assertTrue($entry->subject->is($target));
            $this->assertSame('announcements_editor', $entry->properties['role']);
        }
    }
}
