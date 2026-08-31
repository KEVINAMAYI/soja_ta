<?php

namespace App\Http\Controllers\SuperAdmin\QrTokens;

use App\Helpers\PaginationHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\SuperAdmin\QrTokenResource;
use App\Http\Responses\ApiResponse;
use App\Models\Employee;
use App\Services\QrTokenService;
use App\Utils\ApiConstants;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Superadmin/QrTokens')]
class QrTokenController extends Controller
{
    public function __construct(private readonly QrTokenService $service)
    {
    }

    /**
     * GET /super-man/qr-tokens
     *
     * Paginated list of employee QR tokens for a client, filterable by client, status and search term.
     */
    public function index(Request $request)
    {
        $request->validate([
            'search' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:active,revoked',
            'organization_id' => 'nullable|integer|exists:organizations,id',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1',
            'sort' => 'nullable|string',
        ]);

        $query = $this->service->qrTokensQuery();

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->input('search') . '%')
                    ->orWhere('qr_code', 'like', '%' . $request->input('search') . '%');
            });
        }

        if ($request->filled('status')) {
            $request->input('status') === 'active'
                ? $query->whereNull('qr_code_revoked_at')
                : $query->whereNotNull('qr_code_revoked_at');
        }

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->input('organization_id'));
        }

        $tokens = PaginationHelper::paginate($query, $request);

        $tokens->setCollection(
            QrTokenResource::collection($tokens->getCollection())->collection
        );

        return ApiResponse::success(code: ApiConstants::SUCCESS_CODE, data: $tokens);
    }

    /**
     * PUT /super-man/qr-tokens/{employee}/revoke
     *
     * Revoke an employee's QR token.
     */
    public function revoke(Employee $employee)
    {
        $employee = $this->service->revokeToken($employee);

        return ApiResponse::success(new QrTokenResource($employee), message: 'QR token revoked');
    }

    /**
     * PUT /super-man/qr-tokens/{employee}/activate
     *
     * Re-activate a previously revoked employee QR token.
     */
    public function activate(Employee $employee)
    {
        $employee = $this->service->activateToken($employee);

        return ApiResponse::success(new QrTokenResource($employee), message: 'QR token activated');
    }
}
