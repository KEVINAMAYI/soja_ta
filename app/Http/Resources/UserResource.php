<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'employee_id' => $this->employee->id,
            'employee_name' => $this->name,
            'employee_email' => $this->email,
            'employee_phone' => $this->employee->phone,
            'employee_id_number' => $this->employee->id_number,
            'employee_face_id' => $this->employee->face_id,
            'employee_qr_code' => $this->employee->qr_code,
            'employee_organization' => $this->employee->organization,
            'employee_department' => $this->employee->department,
            'roles' => $this->getRoleNames(),
            'permissions' => $this->getAllPermissions()->pluck('name'),
        ];
    }

}
