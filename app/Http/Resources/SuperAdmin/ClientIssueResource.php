<?php

namespace App\Http\Resources\SuperAdmin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientIssueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'organization' => [
                'id' => $this->organization?->id,
                'name' => $this->organization?->name,
                'contact_email' => $this->organization?->email,
            ],
            'subject' => $this->subject,
            'description' => $this->description,
            'status' => $this->status,
            'priority' => $this->priority,
            'resolved_at' => $this->resolved_at,
            'created_by' => $this->creator?->only(['id', 'name', 'email']),
            'updates' => ClientIssueUpdateResource::collection($this->whenLoaded('updates')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
