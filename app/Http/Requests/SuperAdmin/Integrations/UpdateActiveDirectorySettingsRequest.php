<?php

namespace App\Http\Requests\SuperAdmin\Integrations;

use Illuminate\Foundation\Http\FormRequest;

class UpdateActiveDirectorySettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'ad_sync_enabled' => ['required', 'boolean'],
            'ad_tenant_id' => ['nullable', 'string', 'max:255'],
            'ad_client_id' => ['nullable', 'string', 'max:255'],
            'ad_client_secret' => ['nullable', 'string', 'max:255'],
        ];
    }
}
