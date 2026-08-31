<?php

namespace App\Http\Controllers\SuperAdmin\Users;

use App\Helpers\PaginationHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\SuperAdmin\ClientUserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\UserService;
use App\Utils\ApiConstants;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Superadmin/Users')]
class UserController extends Controller
{
    public function __construct(private readonly UserService $service)
    {
    }

    /**
     * GET /super-man/users
     *
     * Paginated list of client users (employees), filterable by client, role, status and search term.
     */
    public function index(Request $request)
    {
        $request->validate([
            'search' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:active,inactive',
            'role' => 'nullable|string|max:255',
            'organization_id' => 'nullable|integer|exists:organizations,id',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1',
            'sort' => 'nullable|string',
        ]);

        $query = $this->service->clientUsersQuery();

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->input('search') . '%')
                    ->orWhere('email', 'like', '%' . $request->input('search') . '%');
            });
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->input('status') === 'active');
        }

        if ($request->filled('role')) {
            $query->whereHas('roles', function ($q) use ($request) {
                $q->where('name', $request->input('role'));
            });
        }

        if ($request->filled('organization_id')) {
            $query->whereHas('employee', function ($q) use ($request) {
                $q->where('organization_id', $request->input('organization_id'));
            });
        }

        $users = PaginationHelper::paginate($query, $request);

        $users->setCollection(
            ClientUserResource::collection($users->getCollection())->collection
        );

        return ApiResponse::success(code: ApiConstants::SUCCESS_CODE, data: $users);
    }

    /**
     * PUT /super-man/users/{user}/toggle-status
     *
     * Activate or deactivate a client user account.
     */
    public function toggleStatus(User $user)
    {
        $user = $this->service->toggleStatus($user);

        return ApiResponse::success(new ClientUserResource($user), message: 'User status updated');
    }
}
