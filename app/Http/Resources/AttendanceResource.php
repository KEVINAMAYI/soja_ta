<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request)
    {
        return [
            'attendance_id' => $this->id,
            'employee_id' => $this->employee->id,
            'employee_name' => $this->employee->user->name,
            'employee_email' => $this->employee->user->email,
            'employee_phone' => $this->employee->phone,
            'employee_id_number' => $this->employee->id_number,
            'employee_qr_code' => $this->employee->qr_code,
            'employee_face_id' => $this->employee->face_id,
            'date' => $this->date ? $this->date->format('Y-m-d') : null,
            'check_in_time' => $this->check_in_time ? $this->check_in_time->format('H:i:s') : null,
            'check_out_time' => $this->check_out_time ? $this->check_out_time->format('H:i:s') : null,
            'worked_hours' => (float)$this->worked_hours,
            'overtime_hours' => (float)$this->overtime_hours,
            'longitude' => $this->longitude,
            'latitude' => $this->latitude,
            'status' => $this->status,
        ];
    }

}
