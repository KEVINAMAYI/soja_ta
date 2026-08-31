<?php

namespace App\Http\Requests\SuperAdmin\Subscriptions;

use Illuminate\Foundation\Http\FormRequest;

class StoreFeatureRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'feature_category_id' => ['sometimes', 'integer', 'exists:feature_categories,id'],
            'name' => ['required', 'string', 'max:255', 'unique:features,name'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:features,slug'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
