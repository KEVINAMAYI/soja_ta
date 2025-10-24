<?php

namespace App\Models;

use App\Helpers\QRCodeGenerator;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'department_id',
        'user_id',
        'name',
        'id_number',
        'email',
        'phone',
        'status',
        'face_id',
        'shift_id',
        'qr_code'
    ];


    protected static function booted()
    {
        static::creating(function ($employee) {
            $orgId = $employee->organization_id;

            // Get the setting for the org
            $setting = OrganizationSetting::where('organization_id', $orgId)
                ->where('key', 'generate_employee_qr_on_create')
                ->first();

            $generateQr = $setting ? filter_var($setting->value, FILTER_VALIDATE_BOOLEAN) : false;

            if ($generateQr && !$employee->qr_code) {
                $employee->qr_code = QRCodeGenerator::generateEmployeeCode(
                    $employee->organization_id,
                    $employee->id ?? (Employee::max('id') + 1)
                );
            }
        });
    }



    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function employeeType()
    {
        return $this->belongsTo(EmployeeType::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    public function overtimes()
    {
        return $this->hasMany(Overtime::class);
    }

    public function serviceUsages()
    {
        return $this->hasMany(ServiceUsage::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function assignments()
    {
        return $this->hasMany(EmployeeAssignment::class);
    }

    public function currentAssignment()
    {
        return $this->hasOne(EmployeeAssignment::class)->where('is_current', true);
    }

    public function weeklyWorkedHours($employeeId)
    {
        // Get worked hours for the past 7 days (last week)
        $startOfWeek = Carbon::now()->startOfWeek();
        $endOfWeek = Carbon::now()->endOfWeek();

        return \DB::table('attendances')
            ->where('employee_id', $employeeId)
            ->whereBetween('date', [$startOfWeek, $endOfWeek])
            ->sum('worked_hours');  // Assuming 'worked_hours' is a field that already has the calculated hours
    }

    public function monthlyWorkedHours($employeeId)
    {
        // Get worked hours for the current month
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        return \DB::table('attendances')
            ->where('employee_id', $employeeId)
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->sum('worked_hours');
    }

    public function weeklyOvertimeHours($employeeId)
    {
        // Get overtime hours for the past 7 days
        $startOfWeek = Carbon::now()->startOfWeek();
        $endOfWeek = Carbon::now()->endOfWeek();

        return \DB::table('attendances')
            ->where('employee_id', $employeeId)
            ->whereBetween('date', [$startOfWeek, $endOfWeek])
            ->sum('overtime_hours');
    }


    public function latestAttendance()
    {
        return $this->hasOne(Attendance::class)->latestOfMany();
    }


}

