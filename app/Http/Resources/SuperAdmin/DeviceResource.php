<?php

namespace App\Http\Resources\SuperAdmin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
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
            'device_name' => $this->device_name,
            'platform' => $this->platform,
            'checkpoint_id' => $this->checkpoint_id,
            'pin' => $this->pin,
            'device_location' => $this->whenLoaded('deviceLocation', fn () => [
                'id' => $this->deviceLocation?->id,
                'name' => $this->deviceLocation?->name,
            ]),
            'work_location' => $this->whenLoaded('deviceLocation', fn () => $this->deviceLocation?->workLocation ? [
                'id' => $this->deviceLocation->workLocation->id,
                'name' => $this->deviceLocation->workLocation->name,
            ] : null),
            'active' => (bool) $this->active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
