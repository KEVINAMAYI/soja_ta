<?php

namespace App\Http\Resources\SuperAdmin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QrTokenResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'employee_id' => $this->id,
            'employee_name' => $this->name,
            'organization_id' => $this->organization_id,
            'company' => $this->organization?->name,
            'token_hash' => $this->qr_code,
            'active' => $this->qr_code_revoked_at === null,
            'revoked_at' => $this->qr_code_revoked_at,
        ];
    }
}
