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

            // Organizations
            ['name' => 'view-organizations', 'category' => 'Organizations'],
            ['name' => 'add-organizations', 'category' => 'Organizations'],
            ['name' => 'edit-organizations', 'category' => 'Organizations'],
            ['name' => 'delete-organizations', 'category' => 'Organizations'],

            // Departments
            ['name' => 'view-departments', 'category' => 'Departments'],
            ['name' => 'add-departments', 'category' => 'Departments'],
            ['name' => 'edit-departments', 'category' => 'Departments'],
            ['name' => 'delete-departments', 'category' => 'Departments'],

            // Employees
            ['name' => 'view-employees', 'category' => 'Employees'],
            ['name' => 'add-employees', 'category' => 'Employees'],
            ['name' => 'edit-employees', 'category' => 'Employees'],
            ['name' => 'delete-employees', 'category' => 'Employees'],

            // Roles
            ['name' => 'view-roles', 'category' => 'Roles'],
            ['name' => 'add-roles', 'category' => 'Roles'],
            ['name' => 'edit-roles', 'category' => 'Roles'],
            ['name' => 'delete-roles', 'category' => 'Roles'],

            // Timesheets
            ['name' => 'checkin-other-employees', 'category' => 'Timesheets'],
            ['name' => 'approve-manual-timesheets', 'category' => 'Timesheets'],
            ['name' => 'view-all-attendance', 'category' => 'Timesheets'],
            ['name' => 'enroll-employee', 'category' => 'Timesheets'],

            // Reports
            ['name' => 'view-own-reports', 'category' => 'Reports'],
            ['name' => 'view-all-reports', 'category' => 'Reports'],

            // Shifts
            ['name' => 'manage-shifts', 'category' => 'Shifts'],

            // Locations
            ['name' => 'manage-work-locations', 'category' => 'Locations'],
            ['name' => 'assign-locations', 'category' => 'Locations'],

        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name']],
                ['category' => $permission['category']]
            );
        }


        // Define specific role-permission mappings
        $rolePermissions = [
            'super-admin' => 'all',
            'admin' => array_column(array_filter($permissions, fn($p) => !str_contains($p['name'], 'organizations')), 'name'),
            'supervisor' => array_column(array_filter($permissions, fn($p) => !str_contains($p['name'], 'organizations')), 'name'),
            'employee' => [],
            'department-manager' => ['approve-manual-timesheets'],
        ];

        // Assign permissions
        foreach ($rolePermissions as $role => $perms) {

            //seed roles for specific organization
            $roleInstance = Role::firstOrCreate(['name' => $role, 'organization_id' => 1]);

            if ($perms === 'all') {
                $roleInstance->syncPermissions(Permission::all());
            } else {
                $roleInstance->syncPermissions($perms);
            }
        }
    }
}

