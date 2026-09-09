<?php

namespace App\Http\Controllers\SuperAdmin\SuperAdmins;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\SuperAdmins\StoreSuperAdminRequest;
use App\Http\Resources\SuperAdmin\UserResource;
use App\Http\Responses\ApiResponse;
use App\Services\SuperAdminAccountService;
use App\Utils\ApiConstants;
use Dedoc\Scramble\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

#[Group('Superadmin/SuperAdmins')]
class SuperAdminController extends Controller
{
    public function __construct(private readonly SuperAdminAccountService $service)
    {
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
