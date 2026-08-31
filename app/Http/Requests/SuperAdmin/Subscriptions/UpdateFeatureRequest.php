<?php

namespace App\Http\Requests\SuperAdmin\Subscriptions;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFeatureRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'feature_category_id' => ['sometimes', 'integer', 'exists:feature_categories,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255', 'unique:features,name,' . $this->route('feature')?->id],
            'slug' => ['sometimes', 'required', 'string', 'max:255', 'unique:features,slug,' . $this->route('feature')?->id],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
