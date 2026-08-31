<?php

namespace App\Http\Requests\SuperAdmin\Subscriptions;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSubscriptionPlanRequest extends FormRequest
{
    public function rules(): array
    {
        $planId = $this->route('plan')?->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', 'unique:subscription_plans,slug,' . $planId],
            'tier' => ['nullable', 'string', 'max:255'],
            'tagline' => ['nullable', 'string'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'max_locations' => ['nullable', 'integer', 'min:0'],
            'max_devices' => ['nullable', 'integer', 'min:0'],
            'is_most_popular' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
