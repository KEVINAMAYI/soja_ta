<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class DeviceService
{
    /**
     * Devices list with Device Name/Platform/Checkpoint ID/Status columns.
     */
    public function devicesQuery(): Builder
    {
        return Device::query()->with(['organization', 'deviceLocation.workLocation']);
    }

    public function createDevice(array $data): Device
    {
        $device = Device::create([
            'organization_id' => $data['organization_id'],
            'device_location_id' => $data['device_location_id'],
            'device_name' => $data['device_name'],
            'platform' => $data['platform'],
            'checkpoint_id' => $data['checkpoint_id'] ?? $this->generateCheckpointId(),
            'pin' => $data['pin'] ?? $this->generatePin(),
            'active' => $data['active'] ?? true,
        ]);

        return $device->fresh(['organization', 'deviceLocation.workLocation']);
    }

    public function updateDevice(Device $device, array $data): Device
    {
        $device->update(
            Arr::where([
                'organization_id' => $data['organization_id'] ?? null,
                'device_location_id' => $data['device_location_id'] ?? null,
                'device_name' => $data['device_name'] ?? null,
                'platform' => $data['platform'] ?? null,
                'checkpoint_id' => $data['checkpoint_id'] ?? null,
                'pin' => $data['pin'] ?? null,
                'active' => array_key_exists('active', $data) ? $data['active'] : null,
            ], fn ($value) => $value !== null)
        );

        return $device->fresh(['organization', 'deviceLocation.workLocation']);
    }

    private function generateCheckpointId(): string
    {
        return 'CHKPT-' . strtoupper(Str::random(5));
    }

    private function generatePin(): string
    {
        return str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
    }
}
