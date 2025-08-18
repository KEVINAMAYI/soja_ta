<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Clear cache
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Define all permissions
        $permissions = [
            // Organization-related
            'view-organizations',
            'add-organizations',
            'edit-organizations',
            'delete-organizations',

            // Employee-related
            'view-employees',
            'add-employees',
            'edit-employees',
            'delete-employees',

            // Roles management
            'view-roles',
            'add-roles',
            'edit-roles',
            'delete-roles',

            // Attendance management
            'manage-employee-attendance',
            'view-all-attendance',

            // Reports
            'manage-reports'
        ];

        // Create all permissions
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Define specific role-permission mappings
        $rolePermissions = [
            'super-admin' => 'all',
            'admin' => array_filter($permissions, fn($p) => !str_contains($p, 'organizations')),
            'supervisor' => array_filter($permissions, fn($p) => !str_contains($p, 'organizations')),
            'employee' => [],
        ];

        // Assign permissions
        foreach ($rolePermissions as $role => $perms) {
            $roleInstance = Role::firstOrCreate(['name' => $role]);

            if ($perms === 'all') {
                $roleInstance->syncPermissions(Permission::all());
            } else {
                $roleInstance->syncPermissions($perms);
            }
        }
    }
}

