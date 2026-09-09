<?php

namespace App\Http\Controllers\SuperAdmin\Checkpoints;

use App\Helpers\PaginationHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\Checkpoints\StoreCheckpointRequest;
use App\Http\Requests\SuperAdmin\Checkpoints\UpdateCheckpointRequest;
use App\Http\Resources\SuperAdmin\CheckpointResource;
use App\Http\Responses\ApiResponse;
use App\Models\DeviceLocation;
use App\Services\CheckpointService;
use App\Utils\ApiConstants;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Superadmin/Checkpoints')]
class CheckpointController extends Controller
{
    public function __construct(private readonly CheckpointService $service)
    {
    }

    /**
     * GET /super-man/checkpoints
     *
     * Paginated checkpoint (device location) list, filterable by client, work location, status and search term.
     */
    public function index(Request $request)
    {
        $request->validate([
            'search' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:active,inactive',
            'organization_id' => 'nullable|integer|exists:organizations,id',
            'work_location_id' => 'nullable|integer|exists:work_locations,id',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1',
            'sort' => 'nullable|string',
        ]);

        $query = $this->service->checkpointsQuery();

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->input('search') . '%');
        }

        if ($request->filled('status')) {
            $query->where('active', $request->input('status') === 'active');
        }

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->input('organization_id'));
        }

        if ($request->filled('work_location_id')) {
            $query->where('work_location_id', $request->input('work_location_id'));
        }

        $checkpoints = PaginationHelper::paginate($query, $request);

        $checkpoints->setCollection(
            CheckpointResource::collection($checkpoints->getCollection())->collection
        );

        return ApiResponse::success(code: ApiConstants::SUCCESS_CODE, data: $checkpoints);
    }

    /**
     * POST /super-man/checkpoints
     *
     * Create a checkpoint for a client organization's work location.
     */
    public function store(StoreCheckpointRequest $request)
    {
        $checkpoint = $this->service->createCheckpoint($request->validated());

        return ApiResponse::success(new CheckpointResource($checkpoint), message: 'Checkpoint created', httpStatusCode: 201);
    }

    /**
     * PUT /super-man/checkpoints/{checkpoint}
     *
     * Update a client organization's checkpoint.
     */
    public function update(UpdateCheckpointRequest $request, DeviceLocation $checkpoint)
    {
        $checkpoint = $this->service->updateCheckpoint($checkpoint, $request->validated());

        return ApiResponse::success(new CheckpointResource($checkpoint), message: 'Checkpoint updated');
    }
}
