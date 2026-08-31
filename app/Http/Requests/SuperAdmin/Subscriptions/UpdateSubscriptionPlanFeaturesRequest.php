<?php

namespace App\Http\Requests\SuperAdmin\Subscriptions;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSubscriptionPlanFeaturesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'features' => ['nullable', 'array'],
            'features.*.id' => ['required_with:features', 'integer', 'exists:features,id'],
            'features.*.enabled' => ['required_with:features', 'boolean'],
            'sub_features' => ['nullable', 'array'],
            'sub_features.*.id' => ['required_with:sub_features', 'integer', 'exists:sub_features,id'],
            'sub_features.*.enabled' => ['required_with:sub_features', 'boolean'],
        ];
    }
}
