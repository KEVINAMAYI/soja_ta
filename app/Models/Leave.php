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
        'status',
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
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }
}
