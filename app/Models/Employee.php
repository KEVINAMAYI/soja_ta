<?php

namespace App\Models;

use App\Helpers\QRCodeGenerator;
use App\Models\Scopes\ExcludeSystemUsersScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

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
        'zkbio_pin',
        'grade',
        'is_student',
        'is_system_user',
        'ad_employee_id',
        'division',
        'section',
        'unit_id',
        'section_id',
        'subsection_id',
        'ad_upn',
        'ad_synced_at',
        'ad_object_id'
    ];


    protected static function booted()
    {
        static::addGlobalScope(new ExcludeSystemUsersScope);

        static::saving(function ($employee) {
            // Auto-adopt the department's default shift when an employee is newly
            // created or moved into a department, but never override a shift the
            // caller explicitly set — this is a convenience default, not enforced.
            if (empty($employee->shift_id) && $employee->department_id && $employee->isDirty('department_id')) {
                $defaultShiftId = Department::find($employee->department_id)?->default_shift_id;
                if ($defaultShiftId) {
                    $employee->shift_id = $defaultShiftId;
                }
            }

            // Track when an employee was deactivated so the retention policy
            // (System Settings > Employee Defaults) can age them out of the
            // default Inactive view after the configured number of days.
            if ($employee->isDirty('active')) {
                $employee->deactivated_at = $employee->active ? null : now();
            }
        });

        static::creating(function ($employee) {
            $orgId = $employee->organization_id;

            // QR code generation
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

            // ZKBio PIN — org-scoped, only if org has ZKBio enabled
            if (empty($employee->zkbio_pin)) {
                $org = $employee->organization ?? \App\Models\Organization::find($orgId);
                if ($org?->zkbio_enabled) {
                    $employee->zkbio_pin = self::generateZKBioPin($orgId);
                }
            }
        });

        static::saved(function ($employee) {
            if ($employee->shift_id && ($employee->wasRecentlyCreated || $employee->wasChanged('shift_id'))) {
                $employee->syncPrimaryShift();
            }
        });
    }

    /**
     * Include system-user (non-trackable) employee rows, which are hidden
     * by default via ExcludeSystemUsersScope.
     */
    public function scopeWithSystemUsers(Builder $query): Builder
    {
        return $query->withoutGlobalScope(ExcludeSystemUsersScope::class);
    }

    /**
     * Keep the employee_shifts pivot in step with the shift_id column.
     * shift_id is the legacy single-shift field; shifts() is the pivot
     * relation the UI/reports read from — without this they drift apart
     * and employees show up with no shift assigned.
     */
    public function syncPrimaryShift(): void
    {
        if (!$this->shift_id) return;

        DB::table('employee_shifts')->insertOrIgnore([
            'employee_id' => $this->id,
            'shift_id' => $this->shift_id,
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employee_shifts')
            ->where('employee_id', $this->id)
            ->where('shift_id', $this->shift_id)
            ->update(['is_primary' => true, 'updated_at' => now()]);

        DB::table('employee_shifts')
            ->where('employee_id', $this->id)
            ->where('shift_id', '!=', $this->shift_id)
            ->update(['is_primary' => false, 'updated_at' => now()]);
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

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * Named sectionRecord(), not section() — `section` is already a real string
     * column on this model, so a same-named relation method would be shadowed by
     * that attribute and never resolve.
     */
    public function sectionRecord()
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    public function subsectionRecord()
    {
        return $this->belongsTo(Subsection::class, 'subsection_id');
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


    public static function generateZKBioPin(int $organizationId): string
    {
        // Get the org's configured starting PIN
        $org = \App\Models\Organization::find($organizationId);
        $pinStart = (int)($org?->zkbio_pin_start ?? 4000);

        // Get highest existing PIN for this org only — must see system-user rows too,
        // since the zkbio_pin column is unique across the whole table regardless of that flag.
        $max = self::withSystemUsers()->withTrashed()
            ->where('organization_id', $organizationId)
            ->whereNotNull('zkbio_pin')
            ->selectRaw('MAX(CAST(zkbio_pin AS UNSIGNED)) as max_pin')
            ->value('max_pin');

        // Start from pinStart if no pins exist yet, otherwise increment from max
        $next = max(($max ?? 0) + 1, $pinStart + 1);

        // Race condition safety
        while (self::withSystemUsers()->withTrashed()->where('zkbio_pin', (string)$next)->exists()) {
            $next++;
        }

        return (string)$next;
    }


    /** True if this employee belongs to a school org */
    public function isSchoolOrg(): bool
    {
        return (bool)($this->organization?->is_student_record ?? false);
    }

    /** Display label: Student or Staff/Employee */
    public function personTypeLabel(): string
    {
        if (!$this->isSchoolOrg()) {
            return 'Employee';
        }
        return $this->is_student ? 'Student' : 'Staff';
    }


    public function lastAttendance()
    {
        // 'date' max picks the most recent day; 'id' max breaks same-day ties
        // so a check-out always wins over an earlier check-in on the same day.
        return $this->hasOne(Attendance::class)->ofMany(['date' => 'max', 'id' => 'max']);
    }


    public function zkbioAreas(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(ZkbioArea::class, 'employee_areas', 'employee_id', 'zkbio_area_id')
            ->withTimestamps();
    }


    public function shifts()
    {
        return $this->belongsToMany(Shift::class, 'employee_shifts')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    /**
     * Primary shift (backward-compatible with existing shift_id).
     * Use this for display in UI when showing "the" shift.
     */
    public function primaryShift()
    {
        return $this->belongsToMany(Shift::class, 'employee_shifts')
            ->withPivot('is_primary')
            ->wherePivot('is_primary', true)
            ->withTimestamps();
    }

    /**
     * Convenience: get IDs of all assigned shifts.
     */
    public function getShiftIdsAttribute(): array
    {
        return $this->shifts->pluck('id')->toArray();
    }

    /**
     * Check if employee is assigned to a specific shift.
     */
    public function hasShift(int $shiftId): bool
    {
        return $this->shifts->contains('id', $shiftId);
    }

}
