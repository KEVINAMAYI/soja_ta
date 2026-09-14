<?php

namespace App\Services;

use App\Models\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

class SuperAdminRoleService
{
    public const INTERNAL_PERMISSIONS = [
        'view-organizations',
        'add-organizations',
        'edit-organizations',
        'delete-organizations',
        'impersonate-organizations',
    ];

    public function rolesQuery(): Builder
    {
        return Role::query()
            ->where(function (Builder $query) {
                $query->where('is_internal', true)->orWhereNull('organization_id');
            })
            ->with('permissions')
            // ->withCount('users')
            ->latest();
    }

    public function create(array $data): Role
    {
        $this->assertPermissionsAllowed($data['is_internal'], $data['permissions']);
        $this->assertNameAvailable($data['name']);

        return DB::transaction(function () use ($data) {
            $role = Role::create([
                'name' => strtolower($data['name']),
                'guard_name' => 'web',
                'organization_id' => null,
                'is_internal' => (bool) $data['is_internal'],
            ]);
            $role->syncPermissions($data['permissions']);

            return $role->load('permissions');
        });
    }

    public function update(Role $role, array $data): Role
    {
        $this->assertGlobalRole($role);
        $isInternal = array_key_exists('is_internal', $data)
            ? (bool) $data['is_internal']
            : (bool) $role->is_internal;
        $permissions = $data['permissions'] ?? $role->permissions->pluck('name')->all();
        $this->assertPermissionsAllowed($isInternal, $permissions);
        if (array_key_exists('name', $data)) {
            $this->assertNameAvailable($data['name'], $role->id);
        }

        return DB::transaction(function () use ($role, $data, $isInternal, $permissions) {
            if ($role->name === 'super-admin' && !$isInternal) {
                throw ValidationException::withMessages([
                    'is_internal' => 'The super-admin role must remain internal.',
                ]);
            }

            $role->update([
                'name' => array_key_exists('name', $data) ? strtolower($data['name']) : $role->name,
                'is_internal' => $isInternal,
                'organization_id' => null,
            ]);
            $role->syncPermissions($permissions);

            return $role->fresh('permissions');
        });
    }

    public function delete(Role $role): void
    {
        if ($role->name === 'super-admin') {
            throw ValidationException::withMessages([
                'role' => 'The super-admin role cannot be deleted.',
            ]);
        }

        DB::transaction(function () use ($role) {
            DB::table('model_has_roles')->where('role_id', $role->id)->delete();
            DB::table('role_has_permissions')->where('role_id', $role->id)->delete();
            $role->delete();
        });
    }

    private function assertPermissionsAllowed(bool $isInternal, array $permissions): void
    {
        $webPermissionCount = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $permissions)
            ->count();

        if ($webPermissionCount !== count(array_unique($permissions))) {
            throw ValidationException::withMessages([
                'permissions' => 'Every selected permission must be a valid web permission.',
            ]);
        }

        if ($isInternal) {
            return;
        }

        $forbidden = array_values(array_intersect($permissions, self::INTERNAL_PERMISSIONS));
        if ($forbidden !== []) {
            throw ValidationException::withMessages([
                'permissions' => 'External roles cannot include internal permissions: ' . implode(', ', $forbidden) . '.',
            ]);
        }
    }

    private function assertNameAvailable(string $name, ?int $ignoreId = null): void
    {
        $query = Role::where('guard_name', 'web')->where('name', strtolower($name));
        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => 'That role name is already in use.',
            ]);
        }
    }

    private function assertGlobalRole(Role $role): void
    {
        if (!$role->is_internal && $role->organization_id !== null) {
            throw ValidationException::withMessages([
                'role' => 'Organization-scoped roles cannot be managed as global roles.',
            ]);
        }
    }
}
