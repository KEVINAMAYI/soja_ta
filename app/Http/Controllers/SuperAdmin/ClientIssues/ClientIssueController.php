<?php

namespace App\Http\Controllers\SuperAdmin\ClientIssues;

use App\Helpers\PaginationHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\ClientIssues\StoreClientIssueRequest;
use App\Http\Requests\SuperAdmin\ClientIssues\UpdateClientIssueRequest;
use App\Http\Resources\SuperAdmin\ClientIssueResource;
use App\Http\Responses\ApiResponse;
use App\Models\ClientIssue;
use App\Services\ClientIssueService;
use App\Utils\ApiConstants;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Superadmin/Client Issues')]
class ClientIssueController extends Controller
{
    public function __construct(private readonly ClientIssueService $service)
    {
    }

    public function index(Request $request)
    {
        $request->validate([
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'status' => ['nullable', 'string', 'in:open,in_progress,waiting_on_client,resolved,closed'],
            'priority' => ['nullable', 'string', 'in:low,normal,high,urgent'],
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->service->issuesQuery();
        $query->when($request->filled('organization_id'), fn ($query) => $query->where('organization_id', $request->integer('organization_id')));
        $query->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')));
        $query->when($request->filled('priority'), fn ($query) => $query->where('priority', $request->input('priority')));
        $query->when($request->filled('search'), fn ($query) => $query->where(function ($query) use ($request) {
            $search = '%' . $request->input('search') . '%';
            $query->where('reference', 'like', $search)->orWhere('subject', 'like', $search);
        }));

        return ApiResponse::success(
            code: ApiConstants::SUCCESS_CODE,
            data: ClientIssueResource::collection(PaginationHelper::paginate($query, $request)),
        );
    }

    public function store(StoreClientIssueRequest $request)
    {
        $issue = $this->service->create($request->validated(), $request->user()->id);

        return ApiResponse::success(new ClientIssueResource($issue), message: 'Client issue created and tenant notified.', httpStatusCode: 201);
    }

    public function show(ClientIssue $clientIssue)
    {
        $clientIssue->load(['organization', 'creator', 'updates.creator']);

        return ApiResponse::success(new ClientIssueResource($clientIssue));
    }

    public function update(UpdateClientIssueRequest $request, ClientIssue $clientIssue)
    {
        $issue = $this->service->update($clientIssue, $request->validated(), $request->user()->id);

        return ApiResponse::success(new ClientIssueResource($issue), message: 'Client issue updated and tenant notified.');
    }
}
