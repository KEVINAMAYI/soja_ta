<?php

namespace App\Http\Controllers\SuperAdmin\Subscriptions;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\Subscriptions\StoreSubFeatureRequest;
use App\Http\Requests\SuperAdmin\Subscriptions\UpdateSubFeatureRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Feature;
use App\Models\SubFeature;
use App\Services\SubscriptionPlanService;
use Dedoc\Scramble\Attributes\Group;

#[Group('Superadmin/Subscriptions')]
class SubFeatureController extends Controller
{
    public function __construct(private readonly SubscriptionPlanService $service)
    {
    }

    public function store(StoreSubFeatureRequest $request, Feature $feature)
    {
        $subFeature = $this->service->createSubFeature($feature, $request->validated());

        return ApiResponse::success($subFeature, message: 'Sub-feature created', httpStatusCode: 201);
    }

    public function update(UpdateSubFeatureRequest $request, SubFeature $subFeature)
    {
        $subFeature = $this->service->updateSubFeature($subFeature, $request->validated());

        return ApiResponse::success($subFeature, message: 'Sub-feature updated');
    }

    public function destroy(SubFeature $subFeature)
    {
        $subFeature->delete();

        return ApiResponse::success(null, message: 'Sub-feature deleted');
    }
}
