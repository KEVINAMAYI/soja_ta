<?php

namespace App\Http\Controllers\SuperAdmin\Subscriptions;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\Subscriptions\StoreFeatureCategoryRequest;
use App\Http\Requests\SuperAdmin\Subscriptions\UpdateFeatureCategoryRequest;
use App\Http\Responses\ApiResponse;
use App\Models\FeatureCategory;
use App\Services\SubscriptionPlanService;
use Dedoc\Scramble\Attributes\Group;

#[Group('Superadmin/Subscriptions')]
class FeatureCategoryController extends Controller
{
    public function __construct(private readonly SubscriptionPlanService $service)
    {
    }

    /**
     * GET /super-man/subscriptions/feature-categories
     *
     * Full feature matrix: categories with nested features/sub-features and
     * their enabled state per subscription plan.
     */
    public function index()
    {
        return ApiResponse::success($this->service->getFeatureMatrix());
    }

    public function store(StoreFeatureCategoryRequest $request)
    {
        $category = $this->service->createFeatureCategory($request->validated());

        return ApiResponse::success($category, message: 'Feature category created', httpStatusCode: 201);
    }

    public function update(UpdateFeatureCategoryRequest $request, FeatureCategory $featureCategory)
    {
        $category = $this->service->updateFeatureCategory($featureCategory, $request->validated());

        return ApiResponse::success($category, message: 'Feature category updated');
    }

    public function destroy(FeatureCategory $featureCategory)
    {
        $featureCategory->delete();

        return ApiResponse::success(null, message: 'Feature category deleted');
    }
}
