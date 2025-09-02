<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class EmployeeAssignment extends Model
{
    protected $fillable = ['employee_id', 'work_location_id', 'start_date', 'end_date', 'is_current'];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_current' => 'boolean',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function location()
    {
        return $this->belongsTo(WorkLocation::class, 'work_location_id');
    }

    // scope to get assignments active on a date
    public function scopeActiveOn($q, Carbon $when)
    {
        return $q->where('start_date', '<=', $when->toDateString())
            ->where(function ($q) use ($when) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $when->toDateString());
            });
    }

    public static function findForEmployeeAt(int $employeeId, Carbon $at)
    {
        return self::where('employee_id', $employeeId)
            ->activeOn($at)
            ->orderByDesc('start_date')
            ->first();
    }

    public function assignments()
    {
        return $this->hasMany(EmployeeAssignment::class);
    }

    public function currentAssignment()
    {
        return $this->hasOne(EmployeeAssignment::class)->where('is_current', true);
    }

}

