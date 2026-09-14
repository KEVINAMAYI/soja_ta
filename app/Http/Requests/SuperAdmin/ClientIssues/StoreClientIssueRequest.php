<?php

namespace App\Http\Requests\SuperAdmin\ClientIssues;

use Illuminate\Foundation\Http\FormRequest;

class StoreClientIssueRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'priority' => ['sometimes', 'string', 'in:low,normal,high,urgent'],
        ];
    }
}
