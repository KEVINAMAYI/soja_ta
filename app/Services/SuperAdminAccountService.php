<?php

namespace App\Services;

use App\Jobs\SendSuperAdminWelcomeEmailJob;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SuperAdminAccountService
{
    public const SUPER_ADMIN_ROLE = 'super-admin';

    /**
     * Create a new super admin account with a random password, emailed to them.
     */
    public function createSuperAdmin(array $data): User
    {
        $plainPassword = Str::random(12);

        $user = DB::transaction(function () use ($data, $plainPassword) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($plainPassword),
            ]);

            $user->assignRole(self::SUPER_ADMIN_ROLE);

            return $user;
        });

        // get logged in user organization
        $loggedInUser = auth()->user();
        $organizationId = $loggedInUser->employee->organization_id;

        // create an employee record for this super-admin
        Employee::create([
            'organization_id' => $loggedInUser->employee?->organization_id,
            'user_id' => $user->id,
            'department_id' => $loggedInUser->employee?->department_id,
            'name' => $data['name'],
            'id_number' => $data['email'],
            'email' => $data['email'],
            'phone' => '254700000000',
            'active' => 1,
        ]);

        SendSuperAdminWelcomeEmailJob::dispatch($user->name, $user->email, $plainPassword);

        return $user;
    }

    /**
     * Update a super admin's own profile. Email is never modified here.
     */
    public function updateProfile(User $user, array $data): User
    {
        $user->update([
            'name' => $data['name'],
        ]);

        return $user->fresh();
    }
}
