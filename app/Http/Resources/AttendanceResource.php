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
    public function toArray(Request $request): array
    {
        return [
            'employee_id' => $this->employee_id,
            'date' => $this->date,
            'check_in_time' => optional($this->check_in_time)->format('H:i:s'),
            'check_out_time' => optional($this->check_out_time)->format('H:i:s'),
            'worked_hours' => $this->worked_hours,
            'overtime_hours' => $this->overtime_hours,
            'status' => $this->status,
        ];
    }
}
