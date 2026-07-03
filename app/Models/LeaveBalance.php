<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'employee_id',
        'leave_type_id',
        'year',
        'entitled_days',
        'used_days',
    ];

    protected $casts = [
        'year' => 'integer',
        'entitled_days' => 'decimal:1',
        'used_days' => 'decimal:1',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function remainingDays(): float
    {
        return (float) $this->entitled_days - (float) $this->used_days;
    }
}
