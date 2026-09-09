<?php

namespace App\Http\Requests\SuperAdmin\Checkpoints;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCheckpointRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'work_location_id' => [
                'required',
                'integer',
                Rule::exists('work_locations', 'id')->where('organization_id', $this->input('organization_id')),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
