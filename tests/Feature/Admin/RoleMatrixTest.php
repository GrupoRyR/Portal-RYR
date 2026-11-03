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
            'Eventos',
            'Roles',
            'Auditoría e informe de uso',
            'Configuración',
        ], array_map(fn ($section) => $section->label, RoleMatrix::sections()));

        $this->assertSame(['super_admin', 'portal_admin', 'announcements_editor', 'management'], array_keys(RoleMatrix::ROLES));
        $this->assertSame('Gerencia', RoleMatrix::ROLES['management']);
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
            'Empresas' => [true, false, false, false],
            'Integraciones' => [true, false, false, false],
            'Aplicaciones y accesos' => [true, true, false, false],
            'Categorías' => [true, true, false, false],
            'Anuncios' => [true, true, true, false],
            'Eventos' => [true, true, true, false],
            'Roles' => [true, true, false, false],
            'Auditoría e informe de uso' => [true, true, false, false],
            'Configuración' => [true, false, false, false],
        ], $grants);
    }

    public function test_no_section_is_coming_soon_any_more(): void
    {
        $soon = array_values(array_map(
            fn ($section) => $section->label,
            array_filter(RoleMatrix::sections(), fn ($section) => ! $section->available),
        ));

        $this->assertSame([], $soon);
    }
}
