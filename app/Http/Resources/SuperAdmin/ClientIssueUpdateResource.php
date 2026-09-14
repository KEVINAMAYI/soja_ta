<?php

namespace App\Http\Resources\SuperAdmin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientIssueUpdateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'note' => $this->note,
            'created_by' => $this->creator?->only(['id', 'name', 'email']),
            'created_at' => $this->created_at,
        ];
    }
}
