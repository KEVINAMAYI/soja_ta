<?php

namespace App\Http\Requests\SuperAdmin\Subscriptions;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFeatureCategoryRequest extends FormRequest
{
    public function rules(): array
    {
        $categoryId = $this->route('featureCategory')?->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', 'unique:feature_categories,slug,' . $categoryId],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
