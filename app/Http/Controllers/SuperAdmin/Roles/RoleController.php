<?php

namespace App\Http\Controllers\SuperAdmin\Roles;

use App\Helpers\PaginationHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\Roles\StoreRoleRequest;
use App\Http\Requests\SuperAdmin\Roles\UpdateRoleRequest;
use App\Http\Resources\SuperAdmin\RoleResource;
use App\Http\Responses\ApiResponse;
use App\Models\Role;
use App\Services\SuperAdminRoleService;
use App\Utils\ApiConstants;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

#[Group('Superadmin/Roles')]
class RoleController extends Controller
{
    public function __construct(private readonly SuperAdminRoleService $service)
    {
    }

    public function index(Request $request)
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'is_internal' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->service->rolesQuery();
        $query->when($request->filled('is_internal'), fn ($query) => $query->where('is_internal', $request->boolean('is_internal')));
        $query->when($request->filled('search'), fn ($query) => $query->where('name', 'like', '%' . $request->input('search') . '%'));

        $roles = PaginationHelper::paginate($query, $request);
        $roles->setCollection(RoleResource::collection($roles->getCollection())->collection);

        return ApiResponse::success(code: ApiConstants::SUCCESS_CODE, data: $roles);
    }

    public function store(StoreRoleRequest $request)
    {
        $role = $this->service->create($request->validated());

        return ApiResponse::success(
            code: ApiConstants::SUCCESS_CODE,
            data: new RoleResource($role),
            message: 'Global role created successfully.',
            httpStatusCode: Response::HTTP_CREATED,
        );
    }

    public function update(UpdateRoleRequest $request, Role $role)
    {
        $role = $this->service->update($role, $request->validated());

        return ApiResponse::success(
            code: ApiConstants::SUCCESS_CODE,
            data: new RoleResource($role),
            message: 'Global role updated successfully.',
        );
    }

    public function destroy(Role $role)
    {
        $this->service->delete($role);

        return ApiResponse::success(
            code: ApiConstants::SUCCESS_CODE,
            data: null,
            message: 'Global role deleted successfully.',
        );
    }
}
