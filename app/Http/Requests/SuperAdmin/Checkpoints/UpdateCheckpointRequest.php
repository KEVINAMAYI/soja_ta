<?php

namespace App\Http\Requests\SuperAdmin\Checkpoints;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCheckpointRequest extends FormRequest
{
    public function rules(): array
    {
        $organizationId = $this->input('organization_id') ?? $this->route('checkpoint')?->organization_id;

        return [
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'work_location_id' => [
                'nullable',
                'integer',
                Rule::exists('work_locations', 'id')->where('organization_id', $organizationId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
