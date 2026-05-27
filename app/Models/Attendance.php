<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Attendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'date',
        'check_in_time',
        'check_out_time',
        'worked_hours',
        'overtime_hours',
        'status',
        'latitude',
        'longitude',
        'device_id',
        'work_location_id',
        'is_late_checkin',
        'minutes_late',
        'is_early_checkout',
        'minutes_early',
        'within_grace_period',
        'expected_check_in_time',
        'grace_period_end_time',
        'expected_check_out_time',
        'early_checkout_threshold_time',
        'is_break_checkout',
        'total_break_minutes',
        'paid_break_minutes',
        'excess_break_minutes',
        'is_break_checkout',
        'break_count',
        'scenario',
        'incomplete'
    ];

    protected $casts = [
        'date' => 'date',
        'check_in_time' => 'datetime',
        'check_out_time' => 'datetime',

        // Fix incorrect cast name
        'worked_hours' => 'decimal:2',
        'overtime_hours' => 'decimal:2',

        // Flags
        'is_late_checkin' => 'boolean',
        'is_early_checkout' => 'boolean',
        'within_grace_period' => 'boolean',

        // Time-only fields (cast as strings)
        'expected_check_in_time' => 'string',
        'expected_check_out_time' => 'string',
        'grace_period_end_time' => 'string',
        'early_checkout_threshold_time' => 'string',

        // Integer minute fields
        'minutes_late' => 'integer',
        'minutes_early' => 'integer',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function overtimes()
    {
        return $this->hasMany(Overtime::class);
    }

    public function location()
    {
        return $this->belongsTo(WorkLocation::class, 'work_location_id');
    }
}
