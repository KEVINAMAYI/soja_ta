<?php

namespace App\Http\Controllers\SuperAdmin\WorkLocations;

use App\Helpers\PaginationHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\WorkLocations\StoreWorkLocationRequest;
use App\Http\Requests\SuperAdmin\WorkLocations\UpdateWorkLocationRequest;
use App\Http\Resources\SuperAdmin\WorkLocationResource;
use App\Http\Responses\ApiResponse;
use App\Models\WorkLocation;
use App\Services\WorkLocationService;
use App\Utils\ApiConstants;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Superadmin/WorkLocations')]
class WorkLocationController extends Controller
{
    public function __construct(private readonly WorkLocationService $service)
    {
    }

    /**
     * GET /super-man/work-locations
     *
     * Paginated work location list, filterable by client, status and search term.
     */
    public function index(Request $request)
    {
        $request->validate([
            'search' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:active,inactive',
            'organization_id' => 'nullable|integer|exists:organizations,id',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1',
            'sort' => 'nullable|string',
        ]);

        $query = $this->service->workLocationsQuery();

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->input('search') . '%');
        }

        if ($request->filled('status')) {
            $query->where('active', $request->input('status') === 'active');
        }

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->input('organization_id'));
        }

        $workLocations = PaginationHelper::paginate($query, $request);

        $workLocations->setCollection(
            WorkLocationResource::collection($workLocations->getCollection())->collection
        );

        return ApiResponse::success(code: ApiConstants::SUCCESS_CODE, data: $workLocations);
    }

    /**
     * POST /super-man/work-locations
     *
     * Create a work location for a client organization.
     */
    public function store(StoreWorkLocationRequest $request)
    {
        $workLocation = $this->service->createWorkLocation($request->validated());

        return ApiResponse::success(new WorkLocationResource($workLocation), message: 'Work location created', httpStatusCode: 201);
    }

    /**
     * PUT /super-man/work-locations/{workLocation}
     *
     * Update a client organization's work location.
     */
    public function update(UpdateWorkLocationRequest $request, WorkLocation $workLocation)
    {
        $workLocation = $this->service->updateWorkLocation($workLocation, $request->validated());

        return ApiResponse::success(new WorkLocationResource($workLocation), message: 'Work location updated');
    }
}
