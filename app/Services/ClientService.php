<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\LeaveTypesSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

class ClientService
{
    /**
     * Clients list with Company/Status/Joined/Last Active columns, "last
     * active" being the most recent login among the organization's users.
     */
    public function clientsQuery(): Builder
    {
        return Organization::query()
            ->withCount('employees')
            ->addSelect([
                'last_active_at' => User::query()
                    ->selectRaw('MAX(users.last_login_at)')
                    ->join('employees', 'employees.user_id', '=', 'users.id')
                    ->whereColumn('employees.organization_id', 'organizations.id'),
            ]);
    }

    /**
     * Create a client organization along with its default workspace
     * (shift, department, leave types, roles) and tenant admin account.
     */
    public function createClient(array $data, ?UploadedFile $logo = null): Organization
    {
        return DB::transaction(function () use ($data, $logo) {
            $logoPath = $logo?->store('logos', 'public');

            $organization = Organization::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone_number' => $data['phone_number'],
                'address' => $data['address'] ?? null,
                'website' => $data['website'] ?? null,
                'subscription_plan_id' => $data['subscription_plan_id'],
                'max_locations' => $data['max_locations'] ?? null,
                'max_devices' => $data['max_devices'] ?? null,
                'logo_path' => $logoPath,
                'primary_color' => $data['primary_color'] ?? '#072639',
                'accent_color' => $data['accent_color'] ?? null,
                'active' => true,
            ]);

            $shift = Shift::firstOrCreate(
                ['organization_id' => $organization->id, 'name' => 'Morning Shift'],
                [
                    'start_time' => '08:00:00',
                    'end_time' => '17:00:00',
                    'break_minutes' => 30,
                    'overtime_rate' => 1.5,
                    'status' => 'active',
                    'notes' => 'Standard 8-hour day shift with 30-minute break.',
                ]
            );

            $admin = User::firstOrCreate(
                ['email' => $data['admin_email']],
                [
                    'name' => $data['admin_name'],
                    'password' => bcrypt(Str::random(32)),
                ]
            );

            $department = Department::firstOrCreate(
                ['name' => 'ICT', 'organization_id' => $organization->id],
                ['description' => 'ICT', 'manager_id' => $admin->id]
            );

            LeaveTypesSeeder::seedForOrganization($organization->id);

            $this->setupDefaultRoles($organization, $admin);

            Employee::firstOrCreate(
                ['email' => $data['admin_email']],
                [
                    'organization_id' => $organization->id,
                    'department_id' => $department->id,
                    'shift_id' => $shift->id,
                    'user_id' => $admin->id,
                    'name' => $data['admin_name'],
                    'id_number' => 'ADMIN-' . $organization->id,
                    'phone' => $data['phone_number'],
                    'active' => true,
                ]
            );

            if ($data['send_setup_link'] ?? false) {
                Password::broker()->sendResetLink(
                    ['email' => $admin->email],
                    fn ($user, $token) => $user->sendPasswordResetNotificationWithOrganization($token, $organization)
                );
            }

            return $organization->fresh(['subscriptionPlan']);
        });
    }

    private function setupDefaultRoles(Organization $organization, User $admin): void
    {
        $defaultRoles = ['admin', 'supervisor', 'employee', 'department-manager'];

        foreach ($defaultRoles as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'organization_id' => $organization->id]);
        }

        $allPermissions = Permission::all()->pluck('name')->toArray();

        $rolePermissions = [
            'admin' => array_filter($allPermissions, fn ($p) => !str_contains($p, 'organizations')),
            'supervisor' => array_filter($allPermissions, fn ($p) => !str_contains($p, 'organizations')),
            'employee' => [],
            'department-manager' => ['approve-manual-timesheets'],
        ];

        foreach ($rolePermissions as $role => $perms) {
            Role::where('name', $role)
                ->where('organization_id', $organization->id)
                ->first()
                ?->syncPermissions($perms);
        }

        $adminRole = Role::where('name', 'admin')->where('organization_id', $organization->id)->first();

        if ($adminRole) {
            $admin->assignRole($adminRole);
        }
    }
}
