<?php

namespace App\Http\Controllers\SuperAdmin\Devices;

use App\Helpers\PaginationHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\Devices\StoreDeviceRequest;
use App\Http\Requests\SuperAdmin\Devices\UpdateDeviceRequest;
use App\Http\Resources\SuperAdmin\DeviceResource;
use App\Http\Responses\ApiResponse;
use App\Models\Device;
use App\Services\DeviceService;
use App\Utils\ApiConstants;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Superadmin/Devices')]
class DeviceController extends Controller
{
    public function __construct(private readonly DeviceService $service)
    {
    }

    /**
     * GET /super-man/devices
     *
     * Paginated device list, filterable by client, work location, checkpoint, platform, status and search term.
     */
    public function index(Request $request)
    {
        $request->validate([
            'search' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:active,inactive',
            'platform' => 'nullable|string|in:android,ios',
            'organization_id' => 'nullable|integer|exists:organizations,id',
            'work_location_id' => 'nullable|integer|exists:work_locations,id',
            'device_location_id' => 'nullable|integer|exists:device_locations,id',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1',
            'sort' => 'nullable|string',
        ]);

        $query = $this->service->devicesQuery();

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('device_name', 'like', '%' . $request->input('search') . '%')
                    ->orWhere('checkpoint_id', 'like', '%' . $request->input('search') . '%');
            });
        }

        if ($request->filled('status')) {
            $query->where('active', $request->input('status') === 'active');
        }

        if ($request->filled('platform')) {
            $query->where('platform', $request->input('platform'));
        }

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->input('organization_id'));
        }

        if ($request->filled('device_location_id')) {
            $query->where('device_location_id', $request->input('device_location_id'));
        }

        if ($request->filled('work_location_id')) {
            $query->whereHas('deviceLocation', function ($q) use ($request) {
                $q->where('work_location_id', $request->input('work_location_id'));
            });
        }

        $devices = PaginationHelper::paginate($query, $request);

        $devices->setCollection(
            DeviceResource::collection($devices->getCollection())->collection
        );

        return ApiResponse::success(code: ApiConstants::SUCCESS_CODE, data: $devices);
    }

    /**
     * POST /super-man/devices
     *
     * Create a device for a client organization, linked to a checkpoint (device location).
     */
    public function store(StoreDeviceRequest $request)
    {
        $device = $this->service->createDevice($request->validated());

        return ApiResponse::success(new DeviceResource($device), message: 'Device created', httpStatusCode: 201);
    }

    /**
     * PUT /super-man/devices/{device}
     *
     * Update a client organization's device.
     */
    public function update(UpdateDeviceRequest $request, Device $device)
    {
        $device = $this->service->updateDevice($device, $request->validated());

        return ApiResponse::success(new DeviceResource($device), message: 'Device updated');
    }
}
