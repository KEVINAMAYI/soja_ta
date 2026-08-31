<?php

namespace App\Http\Requests\SuperAdmin\Integrations;

use Illuminate\Foundation\Http\FormRequest;

class UpdateApiDocsSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'api_docs_enabled' => ['required', 'boolean'],
            'api_docs_url' => ['nullable', 'url', 'max:255'],
        ];
    }
}
