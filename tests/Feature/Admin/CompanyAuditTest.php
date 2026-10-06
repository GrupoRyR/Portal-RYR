<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\CompanyEditor;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class CompanyAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        config(['services.microsoft.client_secret' => 'secreto-que-no-debe-aparecer']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super_admin');
    }

    /**
     * @return Collection<int, Activity>
     */
    private function activities(string $event)
    {
        return Activity::query()->where('log_name', 'companies')->where('event', $event)->get();
    }

    public function test_editor_changes_are_logged_with_the_causer_and_only_the_changed_fields(): void
    {
        $company = Company::factory()->create(['name' => 'Segurex', 'short_name' => 'Segurex', 'domains' => ['segurex.com'], 'color_primary' => '#FF671F', 'color_accent' => '#666666', 'color_dark' => '#8F360B']);

        Livewire::actingAs($this->admin)
            ->test(CompanyEditor::class, ['company' => $company])
            ->set('name', 'Segurex Latam')
            ->call('save')
            ->assertHasNoErrors();

        $activity = $this->activities('updated')->sole();

        $this->assertTrue($activity->causer->is($this->admin));
        $this->assertTrue($activity->subject->is($company));
        $this->assertSame(['name' => 'Segurex Latam'], $activity->properties['attributes']);
        $this->assertSame(['name' => 'Segurex'], $activity->properties['old']);
    }

    public function test_creation_is_logged(): void
    {
        $this->actingAs($this->admin);

        $company = Company::factory()->create(['name' => 'Andina']);

        $created = $this->activities('company.created')->sole();
        $this->assertTrue($created->causer->is($this->admin));
        $this->assertTrue($created->subject->is($company));
        $this->assertSame('Andina', $created->properties['attributes']['name']);
    }

    public function test_tenant_changes_are_logged_with_both_tenants(): void
    {
        $company = Company::factory()->create(['tenant_id' => '97cd024c-cd7a-419b-b385-f47b5f5816c8']);
        $this->actingAs($this->admin);

        $company->update(['tenant_id' => '54f53029-e6e2-4b5f-a862-3454ae886468']);

        $activity = $this->activities('company.tenant_changed')->sole();
        $this->assertSame('97cd024c-cd7a-419b-b385-f47b5f5816c8', $activity->properties['old']['tenant_id']);
        $this->assertSame('54f53029-e6e2-4b5f-a862-3454ae886468', $activity->properties['attributes']['tenant_id']);
        $this->assertTrue($activity->causer->is($this->admin));
    }

    public function test_consent_activation_and_deactivation_are_logged(): void
    {
        $company = Company::factory()->create(['is_active' => false, 'consent_verified_at' => null]);

        $company->update(['consent_verified_at' => now()]);
        $company->update(['is_active' => true]);
        $company->update(['is_active' => false]);

        $this->assertCount(1, $this->activities('company.consent_verified'));
        $this->assertCount(1, $this->activities('company.activated'));
        $this->assertCount(1, $this->activities('company.deactivated'));
    }

    public function test_no_secret_is_ever_stored(): void
    {
        $this->actingAs($this->admin);
        $company = Company::factory()->create();
        $company->update(['tenant_id' => '54f53029-e6e2-4b5f-a862-3454ae886468', 'consent_verified_at' => now(), 'is_active' => true]);

        $stored = json_encode(DB::table('activity_log')->get());

        $this->assertStringNotContainsString('secreto-que-no-debe-aparecer', $stored);
        $this->assertStringNotContainsStringIgnoringCase('access_token', $stored);
    }
}
