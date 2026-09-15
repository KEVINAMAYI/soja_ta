<?php

namespace App\Http\Controllers\SuperAdmin\Subscriptions;

use App\Helpers\PaginationHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\Subscriptions\StoreFeatureRequest;
use App\Http\Requests\SuperAdmin\Subscriptions\UpdateFeatureRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Feature;
use App\Services\SubscriptionPlanService;
use App\Utils\ApiConstants;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Superadmin/Subscriptions')]
class FeatureController extends Controller
{
    public function __construct(private readonly SubscriptionPlanService $service)
    {
    }

    /**
     * GET /super-man/features
     *
     * Paginated feature list, filterable by id and name.
     */
    public function index(Request $request)
    {
        $request->validate([
            'id' => 'nullable|integer|exists:features,id',
            'name' => 'nullable|string|max:255',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1',
            'sort' => 'nullable|string',
        ]);

        $query = $this->service->featuresQuery();

        if ($request->filled('id')) {
            $query->where('id', $request->input('id'));
        }

        if ($request->filled('name')) {
            $query->where('name', 'like', '%' . $request->input('name') . '%');
        }

        $features = PaginationHelper::paginate($query, $request);

        return ApiResponse::success(code: ApiConstants::SUCCESS_CODE, data: $features);
    }

    public function store(StoreFeatureRequest $request)
    {
        $feature = $this->service->createFeature($request->validated());

        return ApiResponse::success($feature, message: 'Feature created', httpStatusCode: 201);
    }

    public function update(UpdateFeatureRequest $request, Feature $feature)
    {
        $feature = $this->service->updateFeature($feature, $request->validated());

        return ApiResponse::success($feature, message: 'Feature updated');
    }

    public function destroy(Feature $feature)
    {
        $feature->delete();

        return ApiResponse::success(null, message: 'Feature deleted');
    }
}
