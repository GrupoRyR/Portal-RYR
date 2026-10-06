<?php

namespace App\Policies;

use App\Models\User;

/**
 * Gates of the admin sections that have no model of their own. Registered
 * in AppServiceProvider; the roles matrix (App\Admin\RoleMatrix) reads them.
 */
final class AdminSections
{
    /** Auditoría and Informe de uso. */
    public const ACTIVITY = 'viewActivity';

    /** Integraciones (Phase 8): API tokens open the portal to other systems. */
    public const INTEGRATIONS = 'manageIntegrations';

    /** Configuración (Phase 7): portal-wide settings, super admins only. */
    public const SETTINGS = 'manageSettings';

    public static function viewActivity(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'portal_admin']);
    }

    public static function manageIntegrations(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public static function manageSettings(User $user): bool
    {
        return $user->hasRole('super_admin');
    }
}
