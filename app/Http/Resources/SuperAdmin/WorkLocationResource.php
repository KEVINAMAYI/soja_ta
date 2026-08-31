<?php

namespace App\Http\Resources\SuperAdmin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkLocationResource extends JsonResource
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
            'name' => ucwords(str_replace('_', ' ', $this->name)),
            'type' => $this->type,
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'radius_m' => $this->radius_m,
            'description' => $this->description,
            'checkpoints' => $this->checkpoints_count ?? 0,
            'employees_assigned' => $this->employees_assigned_count ?? 0,
            'active' => (bool) $this->active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
