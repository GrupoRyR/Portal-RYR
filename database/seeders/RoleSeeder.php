<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The portal roles. Safe to run any number of times.
 */
class RoleSeeder extends Seeder
{
    public const ROLES = ['super_admin', 'portal_admin', 'announcements_editor'];

    public function run(): void
    {
        // Model events are muted by DatabaseSeeder, so the permission cache
        // is cleared explicitly.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
