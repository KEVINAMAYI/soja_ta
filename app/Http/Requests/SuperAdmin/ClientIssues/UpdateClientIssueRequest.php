<?php

namespace App\Http\Requests\SuperAdmin\ClientIssues;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClientIssueRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'required', 'string', 'max:10000'],
            'status' => ['sometimes', 'required', 'string', 'in:open,in_progress,waiting_on_client,resolved,closed'],
            'priority' => ['sometimes', 'required', 'string', 'in:low,normal,high,urgent'],
            'note' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
