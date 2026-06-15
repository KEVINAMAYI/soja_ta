<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CheckInApprovalRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'employee_id',
        'date',
        'check_in_time',
        'minutes_late',
        'latitude',
        'longitude',
        'device_id',
        'work_location_id',
        'is_late_checkin',
        'within_grace_period',
        'expected_check_in_time',
        'grace_period_end_time',
        'expected_check_out_time',
        'early_checkout_threshold_time',
        'status',
        'current_window',
        'submitted_at',
        'resolved_at',
        'resolved_by',
        'attendance_id',
        'notes',
    ];

    protected $casts = [
        'date' => 'date',
        'check_in_time' => 'datetime',
        'submitted_at' => 'datetime',
        'resolved_at' => 'datetime',
        'minutes_late' => 'integer',
        'current_window' => 'integer',
        'is_late_checkin' => 'boolean',
        'within_grace_period' => 'boolean',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function attendance()
    {
        return $this->belongsTo(Attendance::class);
    }

    public function resolvedBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'resolved_by');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function windowLogs()
    {
        return $this->hasMany(ApprovalWindowLog::class);
    }

    public function activeWindowLog()
    {
        return $this->hasOne(ApprovalWindowLog::class)
            ->where('window_number', $this->current_window)
            ->where('status', 'pending');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
