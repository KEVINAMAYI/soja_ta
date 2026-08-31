<?php

namespace App\Http\Requests\SuperAdmin\Clients;

use Illuminate\Foundation\Http\FormRequest;

class ClientEmployeeDefaultsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'generate_employee_qr_on_create' => ['nullable', 'boolean'],
            'require_employee_photo' => ['nullable', 'boolean'],
            'auto_assign_employee_id' => ['nullable', 'boolean'],
        ];
    }
}
