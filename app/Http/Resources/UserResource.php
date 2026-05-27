<?php

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\WorkLocationResource;

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
            'employee_work_locations' => $this->employee->currentAssignment()
                ->with('location')
                ->get()
                ->map(fn($a) => $a->location)
                ->filter()
                ->values(),
            'roles' => $this->getRoleNames(),
            'permissions' => $this->getAllPermissions()->pluck('name'),
            'working_hours' => [
                'weekly_worked_hours' => $this->employee->weeklyWorkedHours($this->employee->id),
                'monthly_worked_hours' => $this->employee->monthlyWorkedHours($this->employee->id),
                'weekly_overtime_hours' => $this->employee->weeklyOvertimeHours($this->employee->id),
            ]
        ];
    }

}
