<?php

namespace App\Http\Requests\SuperAdmin\Integrations;

use Illuminate\Foundation\Http\FormRequest;

class UpdateZkbioSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'zkbio_enabled' => ['required', 'boolean'],
            'zkbio_base_url' => ['nullable', 'url', 'max:255'],
            'zkbio_access_token' => ['nullable', 'string', 'max:255'],
        ];
    }
}
