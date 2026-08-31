<?php

namespace App\Http\Requests\SuperAdmin\Subscriptions;

use Illuminate\Foundation\Http\FormRequest;

class StoreSubFeatureRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'feature_id' => ['sometimes', 'integer', 'exists:features,id'],
            'name' => ['required', 'string', 'max:255', 'unique:sub_features,name'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:sub_features,slug'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
