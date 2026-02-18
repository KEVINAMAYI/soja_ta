<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Shift extends Model
{
    use HasFactory;

    // ... [Keep all existing properties and methods from the original model]

    protected $table = 'shifts';

    protected $fillable = [
        'organization_id',
        'name',
        'start_time',
        'end_time',
        'duration_hours',
        'break_minutes', // DEPRECATED - keeping for backward compatibility
        'overtime_rate',
        'overtime_enabled',
        'max_overtime_hours',
        'auto_clock_out',
        'warning_time_minutes',
        'pattern_type',
        'pattern_days',
        'notify_managers_overtime',
        'employee_mobile_notifications',
        'email_summaries',
        'status',
        'notes',
        'grace_period_enabled',
        'grace_period_minutes',
        'track_late_checkin',
        'notify_on_late_checkin',
        'track_early_checkout',
        'early_checkout_threshold_minutes',
    ];

    protected $casts = [
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'duration_hours' => 'decimal:2',
        'max_overtime_hours' => 'decimal:2',
        'warning_time_minutes' => 'integer',
        'overtime_enabled' => 'boolean',
        'auto_clock_out' => 'boolean',
        'notify_managers_overtime' => 'boolean',
        'employee_mobile_notifications' => 'boolean',
        'email_summaries' => 'boolean',
        'pattern_days' => 'array',
        'grace_period_enabled' => 'boolean',
        'track_late_checkin' => 'boolean',
        'notify_on_late_checkin' => 'boolean',
        'track_early_checkout' => 'boolean',
        'grace_period_minutes' => 'integer',
        'early_checkout_threshold_minutes' => 'integer',
    ];

    protected $attributes = [
        'duration_hours' => 8.00,
        'overtime_enabled' => true,
        'max_overtime_hours' => 2.00,
        'auto_clock_out' => false,
        'warning_time_minutes' => 30,
        'pattern_type' => 'weekdays',
        'notify_managers_overtime' => false,
        'employee_mobile_notifications' => true,
        'email_summaries' => false,
        'grace_period_enabled' => true,
        'grace_period_minutes' => 15,
        'track_late_checkin' => true,
        'notify_on_late_checkin' => false,
        'track_early_checkout' => true,
        'early_checkout_threshold_minutes' => 15,
    ];

    /**
     * ========================================
     * NEW RELATIONSHIPS FOR BREAKS
     * ========================================
     */

    public function breaks()
    {
        return $this->hasMany(ShiftBreak::class)->ordered();
    }

    public function activeBreaks()
    {
        return $this->hasMany(ShiftBreak::class)->active()->ordered();
    }

    public function mandatoryBreaks()
    {
        return $this->hasMany(ShiftBreak::class)->active()->mandatory()->ordered();
    }

    // ... [Keep all existing relationships]

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function employees()
    {
        return $this->hasMany(Employee::class)->withTrashed();
    }

    /**
     * ========================================
     * NEW BREAK-RELATED METHODS
     * ========================================
     */

    /**
     * Get total break minutes (sum of all active breaks)
     */
    public function getTotalBreakMinutes(): int
    {
        return $this->activeBreaks()
            ->where('type', '!=', 'paid') // Only unpaid breaks reduce working time
            ->sum('duration_minutes');
    }

    /**
     * Get total paid break minutes
     */
    public function getTotalPaidBreakMinutes(): int
    {
        return $this->activeBreaks()
            ->where('type', 'paid')
            ->sum('duration_minutes');
    }

    /**
     * Calculate effective working hours (excluding unpaid breaks)
     */
    public function getEffectiveWorkingHours(): float
    {
        if ($this->start_time && $this->end_time) {
            try {
                $baseDate = now()->startOfDay();

                $start = $baseDate->copy()->setTime(
                    $this->start_time->hour,
                    $this->start_time->minute,
                    $this->start_time->second
                );

                $end = $baseDate->copy()->setTime(
                    $this->end_time->hour,
                    $this->end_time->minute,
                    $this->end_time->second
                );

                // Handle overnight shifts
                if ($end->lt($start)) {
                    $end->addDay();
                }

                // Get total unpaid break minutes from breaks table
                $unpaidBreakMinutes = $this->getTotalBreakMinutes();

                $rawMinutes = $start->diffInMinutes($end);
                $workingMinutes = max(0, $rawMinutes - $unpaidBreakMinutes);

                return round($workingMinutes / 60, 2);

            } catch (\Exception $e) {
                return 0;
            }
        }

        return 0;
    }

    /**
     * Initialize break logs for an attendance record
     */
    public function initializeBreakLogs(Attendance $attendance): void
    {
        $activeBreaks = $this->activeBreaks;

        foreach ($activeBreaks as $break) {
            AttendanceBreakLog::create([
                'attendance_id' => $attendance->id,
                'shift_break_id' => $break->id,
                'status' => 'pending',
                'is_taken' => false,
                'is_compliant' => true,
            ]);
        }
    }

    /**
     * Check if all mandatory breaks are completed
     */
    public function areAllMandatoryBreaksCompleted(Attendance $attendance): bool
    {
        $mandatoryBreakIds = $this->mandatoryBreaks()->pluck('id');

        $completedCount = AttendanceBreakLog::where('attendance_id', $attendance->id)
            ->whereIn('shift_break_id', $mandatoryBreakIds)
            ->whereIn('status', ['completed', 'exceeded'])
            ->count();

        return $completedCount === $mandatoryBreakIds->count();
    }

    /**
     * Get next scheduled break for current time
     */
    public function getNextScheduledBreak(?Carbon $currentTime = null): ?ShiftBreak
    {
        $currentTime = $currentTime ?? now();

        return $this->activeBreaks()
            ->where('window_start_time', '>', $currentTime->format('H:i:s'))
            ->first();
    }

    /**
     * Get current available break (within window)
     */
    public function getCurrentAvailableBreak(?Carbon $currentTime = null): ?ShiftBreak
    {
        $currentTime = $currentTime ?? now();

        foreach ($this->activeBreaks as $break) {
            if ($break->isWithinWindow($currentTime)) {
                return $break;
            }
        }

        return null;
    }

    /**
     * ========================================
     * UPDATED DURATION CALCULATION
     * ========================================
     */

    /**
     * Get shift duration (excluding unpaid breaks)
     * This overrides/updates the existing getDurationAttribute
     */
    public function getDurationAttribute(): ?float
    {
        // Use the new method that accounts for breaks
        return $this->getEffectiveWorkingHours();
    }

    /**
     * Get pattern display
     */
    public function getPatternDisplayAttribute(): string
    {
        $patterns = [
            'weekdays' => 'Weekdays Only',
            'weekends' => 'Weekends Only',
            'daily' => 'Daily',
            'rotating' => 'Rotating Schedule',
            'custom' => 'Custom Days',
        ];

        $patternName = $patterns[$this->pattern_type] ?? 'Custom';

        if (in_array($this->pattern_type, ['custom', 'rotating']) && $this->pattern_days) {
            return $patternName . ' (' . implode(', ', $this->pattern_days) . ')';
        }

        return $patternName;
    }

    /**
     * Get employee count
     */
    public function getEmployeeCountAttribute(): int
    {
        return $this->employees()->where('active', true)->count();
    }

    /**
     * Calculate auto clock-out time
     */
    public function getAutoClockOutTimeAttribute(): ?Carbon
    {
        if (!$this->auto_clock_out) {
            return null;
        }

        $endTime = Carbon::parse($this->end_time);

        if ($this->overtime_enabled) {
            return $endTime->addHours($this->max_overtime_hours);
        }

        return $endTime;
    }

    /**
     * Calculate warning time
     */
    public function getWarningTimeAttribute(): ?Carbon
    {
        if (!$this->auto_clock_out) {
            return null;
        }

        $autoClockOutTime = $this->getAutoClockOutTimeAttribute();

        if ($autoClockOutTime) {
            return $autoClockOutTime->copy()->subMinutes($this->warning_time_minutes);
        }

        return null;
    }

    /**
     * Check if shift is active on a specific day
     */
    public function isActiveOnDay(string $dayAbbreviation): bool
    {
        return in_array($dayAbbreviation, $this->pattern_days ?? []);
    }

    /**
     * Scope for active shifts
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for shifts by pattern
     */
    public function scopeByPattern($query, string $pattern)
    {
        return $query->where('pattern_type', $pattern);
    }

    /**
     * Get grace period end time for late check-ins
     */
    public function getGracePeriodEndTime(): Carbon
    {
        $shiftStart = Carbon::parse($this->start_time);
        $graceMinutes = $this->grace_period_enabled ? $this->grace_period_minutes : 0;
        return $shiftStart->copy()->addMinutes($graceMinutes);
    }

    /**
     * Get early checkout threshold time
     */
    public function getEarlyCheckoutThreshold(): Carbon
    {
        $shiftEnd = Carbon::parse($this->end_time);
        $thresholdMinutes = $this->track_early_checkout ? $this->early_checkout_threshold_minutes : 0;
        return $shiftEnd->copy()->subMinutes($thresholdMinutes);
    }

    /**
     * Check if check-in time is within grace period
     */
    public function isWithinGracePeriod(Carbon $checkInTime): bool
    {
        if (!$this->grace_period_enabled) {
            return false;
        }

        $shiftStart = Carbon::parse($this->start_time);
        $gracePeriodEnd = $this->getGracePeriodEndTime();

        return $checkInTime->greaterThan($shiftStart) && $checkInTime->lessThanOrEqualTo($gracePeriodEnd);
    }

    /**
     * Calculate if a check-in time is late
     */
    public function isLateCheckIn(Carbon $checkInTime): bool
    {
        if (!$this->track_late_checkin) {
            return false;
        }

        $gracePeriodEnd = $this->getGracePeriodEndTime();
        return $checkInTime->greaterThan($gracePeriodEnd);
    }

    /**
     * Calculate minutes late
     */
    public function getMinutesLate(Carbon $checkInTime): int
    {
        $shiftStart = Carbon::parse($this->start_time);

        if ($checkInTime->lessThanOrEqualTo($shiftStart)) {
            return 0;
        }

        return $shiftStart->diffInMinutes($checkInTime);
    }

    /**
     * Check if checkout is early
     */
    public function isEarlyCheckOut(Carbon $checkOutTime): bool
    {
        if (!$this->track_early_checkout) {
            return false;
        }

        $earlyThreshold = $this->getEarlyCheckoutThreshold();
        return $checkOutTime->lessThan($earlyThreshold);
    }

    /**
     * Calculate minutes early
     */
    public function getMinutesEarly(Carbon $checkOutTime): int
    {
        $shiftEnd = $checkOutTime->copy()->setTimeFromTimeString($this->end_time);

        if ($checkOutTime->greaterThanOrEqualTo($shiftEnd)) {
            return 0;
        }

        return $checkOutTime->diffInMinutes($shiftEnd);
    }
}
