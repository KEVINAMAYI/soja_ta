<?php

namespace App\Http\Requests\SuperAdmin\Clients;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClientRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Organization / company details
            'name' => ['required', 'string', 'max:255', 'unique:organizations,name,' . $this->route('clientId')],
            'email' => ['required', 'email', 'max:255', 'unique:organizations,email,' . $this->route('clientId')],
            'phone_number' => ['required', 'string', 'max:50', 'unique:organizations,phone_number,' . $this->route('clientId')],
            'address' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],

            // Subscription plan & limit caps
            'subscription_plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'max_locations' => ['nullable', 'integer', 'min:0'],
            'max_devices' => ['nullable', 'integer', 'min:0'],

            // Primary tenant administrator account
            'admin_name' => ['nullable', 'string', 'max:255'],
            'admin_email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'send_setup_link' => ['nullable', 'boolean'],

            // Branding & system colors
            'primary_color' => ['nullable', 'string', 'max:7'],
            'accent_color' => ['nullable', 'string', 'max:7'],
        ];
    }
}
