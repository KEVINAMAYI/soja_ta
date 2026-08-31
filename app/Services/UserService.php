<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class UserService
{
    /**
     * Client users list: users who are employees of a client organization,
     * with their assigned role and account status.
     */
    public function clientUsersQuery(): Builder
    {
        return User::query()
            ->with(['roles:id,name', 'employee:id,user_id,organization_id,active', 'employee.organization:id,name'])
            ->whereHas('employee');
    }

    public function toggleStatus(User $user): User
    {
        $user->update(['is_active' => !$user->is_active]);

        return $user->fresh(['roles', 'employee.organization']);
    }
}
