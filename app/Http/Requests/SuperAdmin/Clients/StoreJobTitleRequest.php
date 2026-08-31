<?php

namespace App\Http\Requests\SuperAdmin\Clients;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJobTitleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:job_titles,name,' . $this->route('jobTitleId')],
            'isActive' => ['required', 'integer', Rule::in([0, 1])],
            'departmentId' => ['required', 'integer', Rule::exists('departments', 'id')->where(function ($query) {
                $query->where('organization_id', $this->route('organization')->id);
            })],
            'description' => ['nullable', 'string', 'max:250'],
        ];
    }
}
