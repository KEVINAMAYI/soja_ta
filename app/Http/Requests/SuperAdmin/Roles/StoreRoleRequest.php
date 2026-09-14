<?php

namespace App\Http\Requests\SuperAdmin\Roles;

use Illuminate\Foundation\Http\FormRequest;

class StoreRoleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'is_internal' => ['required', 'boolean'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', 'distinct', 'exists:permissions,name'],
        ];
    }
}
