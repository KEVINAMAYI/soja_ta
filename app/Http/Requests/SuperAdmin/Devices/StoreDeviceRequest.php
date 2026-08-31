<?php

namespace App\Http\Requests\SuperAdmin\Devices;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeviceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'device_location_id' => ['required', 'integer', 'exists:device_locations,id'],
            'device_name' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'string', 'in:android,ios'],
            'checkpoint_id' => ['nullable', 'string', 'max:50', 'unique:devices,checkpoint_id'],
            'pin' => ['nullable', 'string', 'max:10'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
