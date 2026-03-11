<?php

namespace App\Models;

use App\Helpers\QRCodeGenerator;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use HasFactory, SoftDeletes;

    protected $appends = ['current_status_badge'];

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
        'qr_code',
        'employee_title',
        'shift_status',
        'start_off_shift_date',
        'end_off_shift_date',
        'zkbio_pin'
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

            // Only assign zkbio_pin if org has ZKBio enabled
            if (empty($employee->zkbio_pin)) {
                $org = $employee->organization ?? \App\Models\Organization::find($employee->organization_id);
                if ($org?->zkbio_enabled) {
                    $employee->zkbio_pin = self::generateZKBioPin();
                }
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
        return $this->hasMany(EmployeeAssignment::class)->where('is_current', true);
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


    protected function currentStatusBadge(): Attribute
    {
        return Attribute::make(
            get: function () {
                $today = Carbon::today()->toDateString();

                // 1. Check for Active Off-Shift Status
                if ($this->shift_status === 'off_shift' &&
                    $this->start_off_shift_date <= $today &&
                    $this->end_off_shift_date >= $today) {
                    return '<span class="badge border border-primary text-primary fs-1 fw-bold p-2 bg-transparent">🌙 OFF SHIFT</span>';
                }


                // 1. Check for Active Off-Shift Status
                if ($this->shift_status === 'sick_off' &&
                    $this->start_off_shift_date <= $today &&
                    $this->end_off_shift_date >= $today) {
                    return '<span class="badge border border-primary text-primary fs-1 fw-bold p-2 bg-transparent">🤒 SICK OFF</span>';
                }

                // 2. Check for Active Approved/Pending Leave
                $activeLeave = $this->leaves()
                    ->whereIn('status', ['approved', 'pending'])
                    ->where('start_date', '<=', $today)
                    ->where('end_date', '>=', $today)
                    ->first();

                if ($activeLeave) {
                    // Use the primary outline style for on-leave status
                    $title = htmlspecialchars($activeLeave->leave_type);
                    return "<span class='badge border border-primary text-primary fw-bold p-2 fs-1 bg-transparent' title='{$title}'>📅 ON LEAVE</span>";
                }

                return null;
            },
        );
    }

    public function leaves()
    {
        return $this->hasMany(Leave::class);
    }


    public static function generateZKBioPin(): string
    {
        // Get the next available ID by checking what's already taken
        $max = self::withTrashed() // include soft-deleted to avoid reuse
        ->whereNotNull('zkbio_pin')
            ->selectRaw('MAX(CAST(zkbio_pin AS UNSIGNED)) as max_pin')
            ->value('max_pin');

        $next = max(($max ?? 0) + 1, 1300); // start from 1000 to avoid clashing with legacy device pins like 1, 2, 36

        // Double-check it's not already taken (race condition safety)
        while (self::withTrashed()->where('zkbio_pin', (string) $next)->exists()) {
            $next++;
        }

        return (string) $next;
    }

}
