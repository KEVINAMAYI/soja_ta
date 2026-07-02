<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Leave extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'department_id',
        'organization_id',
        'leave_type',
        'leave_type_id',
        'status',
        'current_level',
        'total_levels',
        'start_date',
        'end_date',
        'reason',
        'contact_during_leave',
        'emergency_contact',
        'handover_to',
        'expected_resumption',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'expected_resumption' => 'date',
        'current_level' => 'integer',
        'total_levels' => 'integer',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function approvalLogs()
    {
        return $this->hasMany(LeaveApprovalLog::class);
    }

    public function activeApprovalLog()
    {
        return $this->hasOne(LeaveApprovalLog::class)
            ->where('level_number', $this->current_level)
            ->where('status', 'pending');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
