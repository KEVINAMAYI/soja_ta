<?php

namespace App\Services;

use App\Models\WorkLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

class WorkLocationService
{
    /**
     * Work locations list with Company/Checkpoints/Employees Assigned/Active columns.
     */
    public function workLocationsQuery(): Builder
    {
        return WorkLocation::query()
            ->with('organization')
            ->withCount(['deviceLocations as checkpoints_count'])
            ->withCount(['assignments as employees_assigned_count']);
    }

    public function createWorkLocation(array $data): WorkLocation
    {
        $workLocation = WorkLocation::create([
            'organization_id' => $data['organization_id'],
            'name' => $data['name'],
            'type' => $data['type'] ?? null,
            'address' => $data['address'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'radius_m' => $data['radius_m'],
            'description' => $data['description'] ?? null,
            'active' => $data['active'] ?? true,
        ]);

        return $workLocation->fresh('organization');
    }

    public function updateWorkLocation(WorkLocation $workLocation, array $data): WorkLocation
    {
        $workLocation->update(
            Arr::where([
                'organization_id' => $data['organization_id'] ?? null,
                'name' => $data['name'] ?? null,
                'type' => $data['type'] ?? null,
                'address' => $data['address'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'radius_m' => $data['radius_m'] ?? null,
                'description' => $data['description'] ?? null,
                'active' => array_key_exists('active', $data) ? $data['active'] : null,
            ], fn ($value) => $value !== null)
        );

        return $workLocation->fresh('organization');
    }
}
