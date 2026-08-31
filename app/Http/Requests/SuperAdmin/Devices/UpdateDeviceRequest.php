<?php

namespace App\Http\Requests\SuperAdmin\Devices;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDeviceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'device_location_id' => ['nullable', 'integer', 'exists:device_locations,id'],
            'device_name' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'string', 'in:android,ios'],
            'checkpoint_id' => ['nullable', 'string', 'max:50', 'unique:devices,checkpoint_id,' . $this->route('device')?->id],
            'pin' => ['nullable', 'string', 'max:10'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
