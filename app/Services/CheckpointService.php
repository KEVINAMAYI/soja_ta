<?php

namespace App\Services;

use App\Models\DeviceLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

class CheckpointService
{
    /**
     * Checkpoints (device locations) list with Company/Work Location/Devices/Active columns.
     */
    public function checkpointsQuery(): Builder
    {
        return DeviceLocation::query()
            ->with(['organization', 'workLocation'])
            ->withCount('devices');
    }

    public function createCheckpoint(array $data): DeviceLocation
    {
        $checkpoint = DeviceLocation::create([
            'organization_id' => $data['organization_id'],
            'work_location_id' => $data['work_location_id'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'active' => $data['active'] ?? true,
        ]);

        return $checkpoint->fresh(['organization', 'workLocation']);
    }

    public function updateCheckpoint(DeviceLocation $checkpoint, array $data): DeviceLocation
    {
        $checkpoint->update(
            Arr::where([
                'organization_id' => $data['organization_id'] ?? null,
                'work_location_id' => $data['work_location_id'] ?? null,
                'name' => $data['name'] ?? null,
                'description' => $data['description'] ?? null,
                'active' => array_key_exists('active', $data) ? $data['active'] : null,
            ], fn ($value) => $value !== null)
        );

        return $checkpoint->fresh(['organization', 'workLocation']);
    }
}
