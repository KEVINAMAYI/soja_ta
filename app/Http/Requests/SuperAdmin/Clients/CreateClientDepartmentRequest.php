<?php

namespace App\Http\Requests\SuperAdmin\Clients;

use Illuminate\Foundation\Http\FormRequest;

class CreateClientDepartmentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:departments,name,' . $this->route('departmentId')],
            'description' => ['nullable', 'string', 'max:255'],
            'manager_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
