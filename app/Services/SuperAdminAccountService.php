<?php

namespace App\Services;

use App\Jobs\SendSuperAdminWelcomeEmailJob;
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
