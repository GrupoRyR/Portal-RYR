<?php

namespace Tests\Feature\Admin;

use App\Admin\RoleMatrix;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The «Qué puede hacer cada rol» table is generated from RoleMatrix; these
 * tests check every cell against the real gates and policies, so the table
 * cannot drift from what each role can actually do.
 */
class RoleMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_the_matrix_has_the_sections_of_the_design_in_order(): void
    {
        $this->assertSame([
            'Empresas',
            'Integraciones',
            'Aplicaciones y accesos',
            'Categorías',
            'Anuncios',
            'Roles',
            'Auditoría e informe de uso',
            'Configuración',
        ], array_map(fn ($section) => $section->label, RoleMatrix::sections()));

        $this->assertSame(['super_admin', 'portal_admin', 'announcements_editor'], array_keys(RoleMatrix::ROLES));
    }

    public function test_every_cell_matches_what_the_role_can_really_do(): void
    {
        foreach (array_keys(RoleMatrix::ROLES) as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            foreach (RoleMatrix::sections() as $section) {
                $this->assertSame(
                    $section->allows($role),
                    $section->check($user),
                    "«{$section->label}» for {$role} does not match the policies.",
                );
            }
        }
    }

    public function test_people_without_a_role_can_do_nothing(): void
    {
        $user = User::factory()->create();

        foreach (RoleMatrix::sections() as $section) {
            $this->assertFalse($section->check($user), "«{$section->label}» is open to users without a role.");
        }
    }

    public function test_the_expected_grants(): void
    {
        $grants = [];
        foreach (RoleMatrix::sections() as $section) {
            $grants[$section->label] = array_map(fn (string $role) => $section->allows($role), array_keys(RoleMatrix::ROLES));
        }

        $this->assertSame([
            'Empresas' => [true, false, false],
            'Integraciones' => [true, false, false],
            'Aplicaciones y accesos' => [true, true, false],
            'Categorías' => [true, true, false],
            'Anuncios' => [true, true, true],
            'Roles' => [true, true, false],
            'Auditoría e informe de uso' => [true, true, false],
            'Configuración' => [true, true, false],
        ], $grants);
    }
}
