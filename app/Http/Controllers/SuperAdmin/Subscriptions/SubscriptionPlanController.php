<?php

namespace App\Http\Controllers\SuperAdmin\Subscriptions;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\Subscriptions\AssignSubscriptionPlanRequest;
use App\Http\Requests\SuperAdmin\Subscriptions\StoreSubscriptionPlanRequest;
use App\Http\Requests\SuperAdmin\Subscriptions\UpdateSubscriptionPlanFeaturesRequest;
use App\Http\Requests\SuperAdmin\Subscriptions\UpdateSubscriptionPlanRequest;
use App\Http\Resources\SuperAdmin\Subscriptions\SubscriptionPlanResource;
use App\Http\Responses\ApiResponse;
use App\Models\Organization;
use App\Models\SubscriptionPlan;
use App\Services\SubscriptionPlanService;
use Dedoc\Scramble\Attributes\Group;

#[Group('Superadmin/Subscriptions')]
class SubscriptionPlanController extends Controller
{
    public function __construct(private readonly SubscriptionPlanService $service)
    {
    }

    /**
     * GET /super-man/subscriptions/plans
     *
     * Plan cards with client counts and location/device limits.
     */
    public function index()
    {
        return ApiResponse::success(SubscriptionPlanResource::collection($this->service->listPlans()));
    }

    public function show(SubscriptionPlan $plan)
    {
        $plan->loadCount('organizations as clients_count');

        return ApiResponse::success(new SubscriptionPlanResource($plan));
    }

    public function store(StoreSubscriptionPlanRequest $request)
    {
        $plan = $this->service->createPlan($request->validated());

        return ApiResponse::success(new SubscriptionPlanResource($plan), message: 'Subscription plan created', httpStatusCode: 201);
    }

    public function update(UpdateSubscriptionPlanRequest $request, SubscriptionPlan $plan)
    {
        $plan = $this->service->updatePlan($plan, $request->validated());

        return ApiResponse::success(new SubscriptionPlanResource($plan), message: 'Subscription plan updated');
    }

    public function destroy(SubscriptionPlan $plan)
    {
        $plan->delete();

        return ApiResponse::success(null, message: 'Subscription plan deleted');
    }

    /**
     * PUT /super-man/subscriptions/plans/{plan}/features
     *
     * Toggle which features/sub-features are enabled for this plan.
     */
    public function updateFeatures(UpdateSubscriptionPlanFeaturesRequest $request, SubscriptionPlan $plan)
    {
        $this->service->syncPlanFeatures(
            $plan,
            $request->validated('features', []),
            $request->validated('sub_features', []),
        );

        return ApiResponse::success($this->service->getFeatureMatrix(), message: 'Plan features updated');
    }

    /**
     * POST /super-man/subscriptions/plans/{plan}/assign
     *
     * Assign a subscription plan to a client organization.
     */
    public function assign(AssignSubscriptionPlanRequest $request, SubscriptionPlan $plan)
    {
        $organization = Organization::findOrFail($request->validated('organization_id'));

        $this->service->assignPlanToOrganization($organization, $plan);

        return ApiResponse::success($organization, message: 'Subscription plan assigned to organization');
    }
}
