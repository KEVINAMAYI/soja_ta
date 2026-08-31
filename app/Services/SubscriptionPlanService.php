<?php

namespace App\Services;

use App\Models\Feature;
use App\Models\FeatureCategory;
use App\Models\Organization;
use App\Models\SubFeature;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Str;

class SubscriptionPlanService
{
    /**
     * Feature categories with nested features/sub-features, each carrying its
     * enabled state per subscription plan (feature matrix table).
     */
    public function getFeatureMatrix(): array
    {
        $plans = SubscriptionPlan::query()->orderBy('sort_order')->get(['id', 'name', 'slug']);

        $categories = FeatureCategory::with([
            'features.subFeatures',
            'features.subscriptionPlans' => fn ($q) => $q->select('subscription_plans.id'),
            'features.subFeatures.subscriptionPlans' => fn ($q) => $q->select('subscription_plans.id'),
        ])->orderBy('sort_order')->get();

        return $categories->map(function (FeatureCategory $category) use ($plans) {
            return [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'features' => $category->features->map(function (Feature $feature) use ($plans) {
                    return $this->mapFeatureWithPlanStatus($feature, $plans);
                })->values(),
            ];
        })->values()->all();
    }

    private function mapFeatureWithPlanStatus(Feature $feature, $plans): array
    {
        return [
            'id' => $feature->id,
            'name' => $feature->name,
            'slug' => $feature->slug,
            'description' => $feature->description,
            'plans' => $plans->mapWithKeys(fn (SubscriptionPlan $plan) => [
                $plan->slug => (bool) $feature->subscriptionPlans->firstWhere('id', $plan->id)?->pivot->enabled,
            ]),
            'sub_features' => $feature->subFeatures->map(fn (SubFeature $subFeature) => [
                'id' => $subFeature->id,
                'name' => $subFeature->name,
                'slug' => $subFeature->slug,
                'description' => $subFeature->description,
                'plans' => $plans->mapWithKeys(fn (SubscriptionPlan $plan) => [
                    $plan->slug => (bool) $subFeature->subscriptionPlans->firstWhere('id', $plan->id)?->pivot->enabled,
                ]),
            ])->values(),
        ];
    }

    public function listPlans()
    {
        return SubscriptionPlan::withCount('organizations as clients_count')
            ->orderBy('sort_order')
            ->get();
    }

    public function createFeatureCategory(array $data): FeatureCategory
    {
        return FeatureCategory::create([
            'name' => $data['name'],
            'slug' => $data['slug'] ?? Str::slug($data['name']),
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
    }

    public function updateFeatureCategory(FeatureCategory $category, array $data): FeatureCategory
    {
        $category->update(array_filter([
            'name' => $data['name'] ?? null,
            'slug' => $data['slug'] ?? null,
            'sort_order' => $data['sort_order'] ?? null,
        ], fn ($value) => $value !== null));

        return $category;
    }

    public function createFeature(FeatureCategory $category, array $data): Feature
    {
        return $category->features()->create([
            'name' => $data['name'],
            'slug' => $data['slug'] ?? Str::slug($data['name']),
            'description' => $data['description'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
    }

    public function updateFeature(Feature $feature, array $data): Feature
    {
        $feature->update(array_filter([
            'feature_category_id' => $data['feature_category_id'] ?? null,
            'name' => $data['name'] ?? null,
            'slug' => $data['slug'] ?? null,
            'description' => $data['description'] ?? null,
            'sort_order' => $data['sort_order'] ?? null,
        ], fn ($value) => $value !== null));

        return $feature;
    }

    public function createSubFeature(Feature $feature, array $data): SubFeature
    {
        return $feature->subFeatures()->create([
            'name' => $data['name'],
            'slug' => $data['slug'] ?? Str::slug($data['name']),
            'description' => $data['description'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
    }

    public function updateSubFeature(SubFeature $subFeature, array $data): SubFeature
    {
        $subFeature->update(array_filter([
            'feature_id' => $data['feature_id'] ?? null,
            'name' => $data['name'] ?? null,
            'slug' => $data['slug'] ?? null,
            'description' => $data['description'] ?? null,
            'sort_order' => $data['sort_order'] ?? null,
        ], fn ($value) => $value !== null));

        return $subFeature;
    }

    public function createPlan(array $data): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'name' => $data['name'],
            'slug' => $data['slug'] ?? Str::slug($data['name']),
            'tier' => $data['tier'] ?? null,
            'tagline' => $data['tagline'] ?? null,
            'price' => $data['price'] ?? null,
            'max_locations' => $data['max_locations'] ?? null,
            'max_devices' => $data['max_devices'] ?? null,
            'is_most_popular' => $data['is_most_popular'] ?? false,
            'sort_order' => $data['sort_order'] ?? 0,
            'active' => $data['active'] ?? true,
        ]);
    }

    public function updatePlan(SubscriptionPlan $plan, array $data): SubscriptionPlan
    {
        $plan->update(array_filter([
            'name' => $data['name'] ?? null,
            'slug' => $data['slug'] ?? null,
            'tier' => $data['tier'] ?? null,
            'tagline' => $data['tagline'] ?? null,
            'price' => $data['price'] ?? null,
            'max_locations' => array_key_exists('max_locations', $data) ? $data['max_locations'] : null,
            'max_devices' => array_key_exists('max_devices', $data) ? $data['max_devices'] : null,
            'is_most_popular' => $data['is_most_popular'] ?? null,
            'sort_order' => $data['sort_order'] ?? null,
            'active' => $data['active'] ?? null,
        ], fn ($value) => $value !== null));

        return $plan;
    }

    /**
     * Sync which features/sub-features are enabled for a plan.
     *
     * @param  array<int, array{id: int, enabled: bool}>  $features
     * @param  array<int, array{id: int, enabled: bool}>  $subFeatures
     */
    public function syncPlanFeatures(SubscriptionPlan $plan, array $features, array $subFeatures): SubscriptionPlan
    {
        $featureSync = collect($features)->mapWithKeys(fn (array $item) => [
            $item['id'] => ['enabled' => (bool) $item['enabled']],
        ])->all();

        $subFeatureSync = collect($subFeatures)->mapWithKeys(fn (array $item) => [
            $item['id'] => ['enabled' => (bool) $item['enabled']],
        ])->all();

        if (!empty($featureSync)) {
            $plan->features()->syncWithoutDetaching($featureSync);
        }

        if (!empty($subFeatureSync)) {
            $plan->subFeatures()->syncWithoutDetaching($subFeatureSync);
        }

        return $plan;
    }

    public function assignPlanToOrganization(Organization $organization, SubscriptionPlan $plan): Organization
    {
        $organization->update(['subscription_plan_id' => $plan->id]);

        return $organization;
    }
}
