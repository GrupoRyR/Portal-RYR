<?php

namespace App\Admin;

use App\Models\Announcement;
use App\Models\App;
use App\Models\AppCategory;
use App\Models\Company;
use App\Models\Event;
use App\Models\User;
use App\Policies\AdminSections;
use Spatie\Permission\Models\Role;

/**
 * «Qué puede hacer cada rol» (docs/diseno/AdminRoles.dc.html): the single
 * source of the roles table. Each row names the gate or policy that really
 * enforces it, and Tests\Feature\Admin\RoleMatrixTest checks every cell
 * against that check, so the table cannot drift from the behavior.
 */
final class RoleMatrix
{
    /** Roles in the order of the table columns, with their labels. */
    public const ROLES = [
        'super_admin' => 'Súper administrador',
        'portal_admin' => 'Administrador',
        'announcements_editor' => 'Editor de anuncios',
    ];

    /**
     * @return list<RoleMatrixSection>
     */
    public static function sections(): array
    {
        return [
            new RoleMatrixSection(
                'Empresas',
                ['super_admin' => true, 'portal_admin' => false, 'announcements_editor' => false],
                fn (User $user) => $user->can('viewAny', Company::class),
            ),
            new RoleMatrixSection(
                'Integraciones',
                ['super_admin' => true, 'portal_admin' => false, 'announcements_editor' => false],
                fn (User $user) => $user->can(AdminSections::INTEGRATIONS),
            ),
            new RoleMatrixSection(
                'Aplicaciones y accesos',
                ['super_admin' => true, 'portal_admin' => true, 'announcements_editor' => false],
                fn (User $user) => $user->can('viewAny', App::class),
            ),
            new RoleMatrixSection(
                'Categorías',
                ['super_admin' => true, 'portal_admin' => true, 'announcements_editor' => false],
                fn (User $user) => $user->can('viewAny', AppCategory::class),
            ),
            new RoleMatrixSection(
                'Anuncios',
                ['super_admin' => true, 'portal_admin' => true, 'announcements_editor' => 'Solo de su empresa'],
                fn (User $user) => $user->can('viewAny', Announcement::class),
            ),
            // No role of its own: the same people as announcements
            // (App\Policies\EventPolicy).
            new RoleMatrixSection(
                'Eventos',
                ['super_admin' => true, 'portal_admin' => true, 'announcements_editor' => 'Solo de su empresa'],
                fn (User $user) => $user->can('viewAny', Event::class),
            ),
            // super_admin and portal_admin are assigned automatically at
            // sign-in, so nobody adds them by hand: both only manage editors.
            new RoleMatrixSection(
                'Roles',
                ['super_admin' => 'Solo editores', 'portal_admin' => 'Solo editores', 'announcements_editor' => false],
                fn (User $user) => $user->can('viewAny', Role::class),
            ),
            new RoleMatrixSection(
                'Auditoría e informe de uso',
                ['super_admin' => true, 'portal_admin' => true, 'announcements_editor' => false],
                fn (User $user) => $user->can(AdminSections::ACTIVITY),
            ),
            new RoleMatrixSection(
                'Configuración',
                ['super_admin' => true, 'portal_admin' => false, 'announcements_editor' => false],
                fn (User $user) => $user->can(AdminSections::SETTINGS),
            ),
        ];
    }
}
