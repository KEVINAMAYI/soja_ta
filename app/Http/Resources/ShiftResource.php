<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'name' => $this->name,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'duration_hours' => (float) $this->duration_hours,
            'break_minutes' => (int) $this->break_minutes,
            'overtime' => [
                'enabled' => (bool) $this->overtime_enabled,
                'rate' => (float) $this->overtime_rate,
                'max_hours' => (float) $this->max_overtime_hours,
                'notify_managers' => (bool) $this->notify_managers_overtime,
            ],
            'grace_period' => [
                'enabled' => (bool) $this->grace_period_enabled,
                'minutes' => (int) $this->grace_period_minutes,
            ],
            'late_checkin' => [
                'tracked' => (bool) $this->track_late_checkin,
                'notify' => (bool) $this->notify_on_late_checkin,
            ],
            'early_checkout' => [
                'tracked' => (bool) $this->track_early_checkout,
                'threshold_minutes' => (int) $this->early_checkout_threshold_minutes,
            ],
            'auto_clock_out' => (bool) $this->auto_clock_out,
            'warning_time_minutes' => (int) $this->warning_time_minutes,
            'pattern' => [
                'type' => $this->pattern_type,
                'days' => $this->pattern_days,
            ],
            'notifications' => [
                'employee_mobile' => (bool) $this->employee_mobile_notifications,
                'email_summaries' => (bool) $this->email_summaries,
            ],
            'status' => $this->status,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
