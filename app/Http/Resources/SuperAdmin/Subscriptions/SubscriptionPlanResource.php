<?php

namespace App\Http\Resources\SuperAdmin\Subscriptions;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'tier' => $this->tier,
            'tagline' => $this->tagline,
            'price' => $this->price,
            'max_locations' => $this->max_locations,
            'max_locations_label' => is_null($this->max_locations) ? 'Unlimited' : $this->max_locations . ' Location' . ($this->max_locations === 1 ? '' : 's'),
            'max_devices' => $this->max_devices,
            'max_devices_label' => is_null($this->max_devices) ? 'Unlimited' : $this->max_devices . ' Terminal' . ($this->max_devices === 1 ? '' : 's'),
            'is_most_popular' => $this->is_most_popular,
            'sort_order' => $this->sort_order,
            'active' => $this->active,
            'clients_count' => $this->clients_count ?? 0,
            'features' => $this->whenLoaded('features', fn () => $this->features->map(fn ($feature) => [
                'id' => $feature->id,
                'name' => $feature->name,
                'slug' => $feature->slug,
                'description' => $feature->description,
                'sort_order' => $feature->sort_order,
                'sub_features' => $feature->subFeatures->map(fn ($subFeature) => [
                    'id' => $subFeature->id,
                    'name' => $subFeature->name,
                    'slug' => $subFeature->slug,
                    'description' => $subFeature->description,
                    'sort_order' => $subFeature->sort_order,
                ]),
            ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
