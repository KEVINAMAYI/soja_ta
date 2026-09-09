<?php

namespace App\Http\Requests\SuperAdmin\SuperAdmins;

use Illuminate\Foundation\Http\FormRequest;

class StoreSuperAdminRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ];
    }
}
