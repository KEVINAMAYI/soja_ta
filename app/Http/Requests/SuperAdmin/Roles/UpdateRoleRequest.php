<?php

namespace App\Http\Requests\SuperAdmin\Roles;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRoleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'is_internal' => ['sometimes', 'required', 'boolean'],
            'permissions' => ['sometimes', 'required', 'array', 'min:1'],
            'permissions.*' => ['string', 'distinct', 'exists:permissions,name'],
        ];
    }
}
