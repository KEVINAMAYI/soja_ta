<?php

namespace App\Http\Requests\SuperAdmin\Auth;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSuperAdminProfileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // email is intentionally excluded here; it cannot be changed via this endpoint
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
