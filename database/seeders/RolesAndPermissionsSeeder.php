<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use App\Models\Organization;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

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
            ['name' => 'view-shift-monitoring', 'category' => 'Shifts'],
            ['name' => 'configure-shift', 'category' => 'Shifts'],

            // Leave
            ['name' => 'create-leave-request', 'category' => 'Leave'],
            ['name' => 'approve-leave-request', 'category' => 'Leave'],

            // Locations
            ['name' => 'manage-work-locations', 'category' => 'Locations'],
            ['name' => 'assign-locations', 'category' => 'Locations'],

            // Dashboard
            ['name' => 'view-dashboard', 'category' => 'Dashboard'],

            // Settings
            ['name' => 'view-settings', 'category' => 'Settings'],

            // School-specific
            ['name' => 'view-students', 'category' => 'School'],
            ['name' => 'add-students', 'category' => 'School'],
            ['name' => 'edit-students', 'category' => 'School'],
            ['name' => 'delete-students', 'category' => 'School'],
            ['name' => 'mark-student-attendance', 'category' => 'School'],
            ['name' => 'mark-staff-attendance', 'category' => 'School'],
            ['name' => 'view-school-attendance', 'category' => 'School'],
            ['name' => 'manage-boarding-status', 'category' => 'School'],
            ['name' => 'manage-special-activities', 'category' => 'School'],
            ['name' => 'view-own-attendance', 'category' => 'School'],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name']],
                ['category' => $permission['category']]
            );
        }

        $allExceptOrg = array_column(
            array_filter($permissions, fn($p) => !str_contains($p['name'], 'organizations')),
            'name'
        );

        $standardRoles = [
            'super-admin' => 'all',

            'admin' => $allExceptOrg,

            'supervisor' => [
                'view-employees',
                'view-departments',
                'checkin-other-employees',
                'approve-manual-timesheets',
                'view-all-attendance',
                'view-all-reports',
                'view-dashboard',
                'assign-locations',
            ],

            'employee' => [
                'view-dashboard',
                'view-own-reports',
            ],

            'department-manager' => [
                'approve-manual-timesheets',
                'view-all-attendance',
                'view-all-reports',
                'view-dashboard',
            ],
        ];

        foreach ($standardRoles as $roleName => $perms) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'organization_id' => 1,
                'guard_name' => 'web',
            ]);

            if ($perms === 'all') {
                $role->syncPermissions(Permission::all());
            } else {
                $role->syncPermissions($perms);
            }
        }

        // Seed school roles for all existing school orgs
        $schoolOrgs = Organization::where('is_student_record', 1)->get();
        foreach ($schoolOrgs as $org) {
            static::createSchoolRoles($org->id);
        }
    }

    /**
     * Create school-specific roles for a given organization.
     * Called automatically from OrganizationObserver when a new school org is created.
     * Can also be called manually: RolesAndPermissionsSeeder::createSchoolRoles($orgId);
     */
    public static function createSchoolRoles(int $organizationId): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $schoolRoles = [

            'school-admin' => [
                'view-employees', 'add-employees', 'edit-employees', 'delete-employees',
                'view-students', 'add-students', 'edit-students', 'delete-students',
                'view-departments', 'add-departments', 'edit-departments',
                'view-roles',
                'mark-student-attendance', 'mark-staff-attendance',
                'view-school-attendance', 'view-all-attendance',
                'manage-boarding-status', 'manage-special-activities',
                'approve-manual-timesheets',
                'view-all-reports', 'view-own-reports',
                'manage-shifts', 'manage-work-locations', 'assign-locations',
                'view-dashboard', 'view-settings', 'enroll-employee',
            ],

            'staff' => [
                'view-students',
                'mark-student-attendance',
                'mark-staff-attendance',
                'view-school-attendance',
                'manage-boarding-status',
                'view-own-reports',
                'view-dashboard',
            ],

            // No permissions — students do not access the backend.
            // Uncomment 'view-own-attendance' if you add a student portal.
            'student' => [
                // 'view-own-attendance',
            ],
        ];

        foreach ($schoolRoles as $roleName => $perms) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'organization_id' => $organizationId,
                'guard_name' => 'web',
            ]);
            $role->syncPermissions($perms);
        }
    }
}
