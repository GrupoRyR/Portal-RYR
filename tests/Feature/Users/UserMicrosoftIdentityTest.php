<?php

namespace Tests\Feature\Users;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserMicrosoftIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '97cd024c-cd7a-419b-b385-f47b5f5816c8';

    private const OID = '11111111-2222-3333-4444-555555555555';

    public function test_users_table_has_microsoft_identity_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('users', [
            'azure_oid',
            'tenant_id',
            'company_id',
            'job_title',
            'photo_path',
            'last_login_at',
        ]));
    }

    public function test_a_microsoft_user_can_be_stored_without_password_or_identity_details(): void
    {
        $user = User::create([
            'name' => 'Ana Pérez',
            'email' => 'ana@segurex.com',
        ]);

        $user->refresh();

        $this->assertNull($user->password);
        $this->assertNull($user->azure_oid);
        $this->assertNull($user->tenant_id);
        $this->assertNull($user->company_id);
        $this->assertNull($user->job_title);
        $this->assertNull($user->photo_path);
        $this->assertNull($user->last_login_at);
    }

    public function test_microsoft_identity_attributes_are_stored_and_cast(): void
    {
        $company = Company::factory()->create();

        $user = User::factory()->create([
            'azure_oid' => strtoupper(self::OID),
            'tenant_id' => strtoupper(self::TENANT),
            'company_id' => $company->id,
            'job_title' => 'Analista',
            'photo_path' => 'avatars/1.jpg',
            'last_login_at' => '2026-10-05 08:00:00',
        ])->refresh();

        $this->assertSame(self::OID, $user->azure_oid);
        $this->assertSame(self::TENANT, $user->tenant_id);
        $this->assertSame('Analista', $user->job_title);
        $this->assertSame('avatars/1.jpg', $user->photo_path);
        $this->assertSame('2026-10-05 08:00:00', $user->last_login_at->format('Y-m-d H:i:s'));
    }

    public function test_the_same_object_id_cannot_repeat_within_a_tenant(): void
    {
        User::factory()->create(['tenant_id' => self::TENANT, 'azure_oid' => self::OID]);

        $this->expectException(QueryException::class);

        User::factory()->create(['tenant_id' => self::TENANT, 'azure_oid' => self::OID]);
    }

    public function test_the_same_object_id_may_exist_in_another_tenant(): void
    {
        User::factory()->create(['tenant_id' => self::TENANT, 'azure_oid' => self::OID]);
        User::factory()->create(['tenant_id' => '54f53029-e6e2-4b5f-a862-3454ae886468', 'azure_oid' => self::OID]);

        $this->assertSame(2, User::where('azure_oid', self::OID)->count());
    }

    public function test_many_users_without_microsoft_identity_may_coexist(): void
    {
        User::factory()->count(2)->create();

        $this->assertSame(2, User::whereNull('azure_oid')->count());
    }

    public function test_a_user_belongs_to_a_company(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $this->assertTrue($user->company->is($company));
    }

    public function test_deleting_a_company_keeps_its_users_without_company(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $company->delete();

        $this->assertNull($user->fresh()->company_id);
    }

    public function test_microsoft_factory_state_builds_a_linked_identity(): void
    {
        $user = User::factory()->microsoft()->create();

        $this->assertNotNull($user->azure_oid);
        $this->assertNotNull($user->tenant_id);
        $this->assertNotNull($user->company);
        $this->assertSame($user->company->tenant_id, $user->tenant_id);
    }

    public function test_microsoft_factory_state_accepts_a_company(): void
    {
        $company = Company::factory()->create();

        $user = User::factory()->microsoft($company)->create();

        $this->assertTrue($user->company->is($company));
        $this->assertSame($company->tenant_id, $user->tenant_id);
    }
}
