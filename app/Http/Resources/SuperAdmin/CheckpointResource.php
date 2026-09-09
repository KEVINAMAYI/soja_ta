<?php

namespace App\Http\Resources\SuperAdmin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CheckpointResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'company' => $this->organization?->name,
            'work_location_id' => $this->work_location_id,
            'work_location' => $this->workLocation?->name,
            'name' => $this->name,
            'description' => $this->description,
            'devices' => $this->devices_count ?? 0,
            'active' => (bool) $this->active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
