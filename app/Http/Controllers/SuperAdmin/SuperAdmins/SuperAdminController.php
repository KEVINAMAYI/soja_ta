<?php

namespace App\Http\Controllers\SuperAdmin\SuperAdmins;

use App\Helpers\PaginationHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\SuperAdmins\StoreSuperAdminRequest;
use App\Http\Resources\SuperAdmin\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\SuperAdminAccountService;
use App\Utils\ApiConstants;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

#[Group('Superadmin/SuperAdmins')]
class SuperAdminController extends Controller
{
    public function __construct(private readonly SuperAdminAccountService $service)
    {
    }

    /**
     * GET /super-man/super-admins
     *
     * Paginated list of super admin accounts, newest first.
     */
    public function index(Request $request)
    {
        $request->validate([
            'search' => 'nullable|string|max:255',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1',
            'sort' => 'nullable|string',
        ]);

        $query = User::query()
            ->whereHas('roles', fn ($roleQuery) => $roleQuery->where('name', 'super-admin'))
            ->latest();

        if ($request->filled('search')) {
            $query->where(function ($userQuery) use ($request) {
                $search = $request->input('search');

                $userQuery->where('name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%');
            });
        }

        $superAdmins = PaginationHelper::paginate($query, $request);
        $superAdmins->setCollection(
            UserResource::collection($superAdmins->getCollection())->collection
        );

        return ApiResponse::success(code: ApiConstants::SUCCESS_CODE, data: $superAdmins);
    }

    /**
     * POST /super-man/super-admins
     *
     * Create a new super admin account. A random password is generated and emailed to them.
     */
    public function store(StoreSuperAdminRequest $request)
    {
        $user = $this->service->createSuperAdmin($request->validated());

        return ApiResponse::success(
            code: ApiConstants::SUCCESS_CODE,
            data: new UserResource($user),
            message: 'Super admin created successfully. Login credentials have been emailed to them.',
            httpStatusCode: Response::HTTP_CREATED,
        );
    }
}
