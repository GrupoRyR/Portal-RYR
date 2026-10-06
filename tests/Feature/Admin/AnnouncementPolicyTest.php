<?php

namespace Tests\Feature\Admin;

use App\Models\Announcement;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AnnouncementPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private Company $derivados;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->segurex = Company::factory()->create();
        $this->derivados = Company::factory()->create();
    }

    public function test_super_and_portal_admins_manage_every_announcement(): void
    {
        $all = Announcement::factory()->create();
        $theirs = Announcement::factory()->forCompanies($this->derivados)->create();
        $both = Announcement::factory()->forCompanies($this->segurex, $this->derivados)->create();

        foreach (['super_admin', 'portal_admin'] as $role) {
            $user = $this->user($role, $this->segurex);

            $this->assertTrue(Gate::forUser($user)->allows('viewAny', Announcement::class), $role);
            $this->assertTrue(Gate::forUser($user)->allows('create', Announcement::class), $role);

            foreach ([$all, $theirs, $both] as $announcement) {
                $this->assertTrue(Gate::forUser($user)->allows('update', $announcement), $role);
            }

            $this->assertTrue($this->canPublish($user, Announcement::AUDIENCE_ALL, []), $role);
            $this->assertTrue($this->canPublish($user, Announcement::AUDIENCE_COMPANIES, [$this->derivados->id]), $role);
        }
    }

    public function test_announcement_editors_work_only_on_their_own_company(): void
    {
        $editor = $this->user('announcements_editor', $this->segurex);

        $this->assertTrue(Gate::forUser($editor)->allows('viewAny', Announcement::class));
        $this->assertTrue(Gate::forUser($editor)->allows('create', Announcement::class));

        $this->assertTrue(Gate::forUser($editor)->allows('update', Announcement::factory()->forCompanies($this->segurex)->create()));
        $this->assertFalse(Gate::forUser($editor)->allows('update', Announcement::factory()->create()), 'all companies');
        $this->assertFalse(Gate::forUser($editor)->allows('update', Announcement::factory()->forCompanies($this->derivados)->create()), 'other company');
        $this->assertFalse(Gate::forUser($editor)->allows('update', Announcement::factory()->forCompanies($this->segurex, $this->derivados)->create()), 'shared');
    }

    public function test_announcement_editors_may_only_address_their_own_company(): void
    {
        $editor = $this->user('announcements_editor', $this->segurex);

        $this->assertTrue($this->canPublish($editor, Announcement::AUDIENCE_COMPANIES, [$this->segurex->id]));
        $this->assertTrue($this->canPublish($editor, Announcement::AUDIENCE_COMPANIES, [(string) $this->segurex->id, $this->segurex->id]));

        $this->assertFalse($this->canPublish($editor, Announcement::AUDIENCE_ALL, []));
        $this->assertFalse($this->canPublish($editor, Announcement::AUDIENCE_COMPANIES, [$this->derivados->id]));
        $this->assertFalse($this->canPublish($editor, Announcement::AUDIENCE_COMPANIES, [$this->segurex->id, $this->derivados->id]));
        $this->assertFalse($this->canPublish($editor, Announcement::AUDIENCE_COMPANIES, []));
    }

    public function test_an_editor_without_a_company_can_do_nothing(): void
    {
        $editor = $this->user('announcements_editor', null);

        $this->assertFalse(Gate::forUser($editor)->allows('create', Announcement::class));
        $this->assertFalse(Gate::forUser($editor)->allows('update', Announcement::factory()->forCompanies($this->segurex)->create()));
        $this->assertFalse($this->canPublish($editor, Announcement::AUDIENCE_COMPANIES, [$this->segurex->id]));
    }

    public function test_everyone_else_is_refused(): void
    {
        $user = User::factory()->microsoft($this->segurex)->create();
        $announcement = Announcement::factory()->forCompanies($this->segurex)->create();

        $this->assertFalse(Gate::forUser($user)->allows('viewAny', Announcement::class));
        $this->assertFalse(Gate::forUser($user)->allows('create', Announcement::class));
        $this->assertFalse(Gate::forUser($user)->allows('update', $announcement));
        $this->assertFalse($this->canPublish($user, Announcement::AUDIENCE_COMPANIES, [$this->segurex->id]));
    }

    public function test_nobody_deletes_announcements(): void
    {
        $announcement = Announcement::factory()->create();

        foreach (['super_admin', 'portal_admin', 'announcements_editor'] as $role) {
            $this->assertFalse(Gate::forUser($this->user($role, $this->segurex))->allows('delete', $announcement), $role);
        }
    }

    public function test_the_admin_list_only_shows_what_an_editor_may_edit(): void
    {
        $mine = Announcement::factory()->forCompanies($this->segurex)->create();
        Announcement::factory()->create();
        Announcement::factory()->forCompanies($this->derivados)->create();
        Announcement::factory()->forCompanies($this->segurex, $this->derivados)->create();

        $editor = $this->user('announcements_editor', $this->segurex);
        $admin = $this->user('portal_admin', null);

        $this->assertSame([$mine->id], Announcement::query()->manageableBy($editor)->pluck('id')->all());
        $this->assertSame(4, Announcement::query()->manageableBy($admin)->count());
        $this->assertSame(0, Announcement::query()->manageableBy(User::factory()->create())->count());
    }

    private function user(string $role, ?Company $company): User
    {
        $user = $company === null ? User::factory()->create() : User::factory()->microsoft($company)->create();
        $user->assignRole($role);

        return $user->fresh();
    }

    /**
     * @param  list<int|string>  $companyIds
     */
    private function canPublish(User $user, string $audience, array $companyIds): bool
    {
        return Gate::forUser($user)->allows('publishTo', [Announcement::class, $audience, $companyIds]);
    }
}
