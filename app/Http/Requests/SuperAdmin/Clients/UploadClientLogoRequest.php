<?php

namespace App\Http\Requests\SuperAdmin\Clients;

use Illuminate\Foundation\Http\FormRequest;

class UploadClientLogoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'logo' => ['required', 'image', 'mimes:jpg,jpeg,png,gif', 'max:2048'],
        ];
    }
}
