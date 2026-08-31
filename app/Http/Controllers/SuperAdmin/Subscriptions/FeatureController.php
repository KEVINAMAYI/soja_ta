<?php

namespace App\Http\Controllers\SuperAdmin\Subscriptions;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\Subscriptions\StoreFeatureRequest;
use App\Http\Requests\SuperAdmin\Subscriptions\UpdateFeatureRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Feature;
use App\Models\FeatureCategory;
use App\Services\SubscriptionPlanService;
use Dedoc\Scramble\Attributes\Group;

#[Group('Superadmin/Subscriptions')]
class FeatureController extends Controller
{
    public function __construct(private readonly SubscriptionPlanService $service)
    {
    }

    public function store(StoreFeatureRequest $request, FeatureCategory $featureCategory)
    {
        $feature = $this->service->createFeature($featureCategory, $request->validated());

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
