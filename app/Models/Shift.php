<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Shift extends Model
{
    use HasFactory;

    // Table name (optional if it follows Laravel convention: shifts)
    protected $table = 'shifts';

    // Fillable fields for mass assignment
    protected $fillable = [
        'organization_id',
        'name',
        'start_time',
        'end_time',
        'duration_hours',
        'break_minutes',
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

    // Casts for correct data types
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

    // Accessor for pattern display
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

    // Accessor for employee count
    public function getEmployeeCountAttribute(): int
    {
        return $this->employees()->where('active', true)->count();
    }

    // Calculate auto clock-out time
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

    // Calculate warning time
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

    // Check if shift is active on a specific day
    public function isActiveOnDay(string $dayAbbreviation): bool
    {
        return in_array($dayAbbreviation, $this->pattern_days ?? []);
    }


    // Scope for active shifts
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    // Scope for shifts by pattern
    public function scopeByPattern($query, string $pattern)
    {
        return $query->where('pattern_type', $pattern);
    }


    /**
     * Accessor for shift duration (excluding break).
     */
    public function getDurationAttribute(): ?float
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

                $breakMinutes = (int)($this->break_minutes ?? 0);

                $rawMinutes = $start->diffInMinutes($end); // ✅ Corrected direction

                $durationMinutes = max(0, $rawMinutes - $breakMinutes);

                return round($durationMinutes / 60, 2); // return float hours

            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }


    public function organization()
    {
        $this->belongsTo(Organization::class);
    }


    public function employees()
    {
        return $this->hasMany(Employee::class);
    }



    /**
     * ========================================
     * ✅ GRACE PERIOD HELPER METHODS
     * ========================================
     */

    /**
     * Get the grace period end time for late check-ins
     */
    public function getGracePeriodEndTime(): Carbon
    {
        $shiftStart = Carbon::parse($this->start_time);
        $graceMinutes = $this->grace_period_enabled ? $this->grace_period_minutes : 0;
        return $shiftStart->copy()->addMinutes($graceMinutes);
    }

    /**
     * Get the early checkout threshold time
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

        // Within grace period if: after shift start BUT before grace period end
        return $checkInTime->greaterThan($shiftStart) && $checkInTime->lessThanOrEqualTo($gracePeriodEnd);
    }

    /**
     * Calculate if a check-in time is late (after grace period)
     */
    public function isLateCheckIn(Carbon $checkInTime): bool
    {
        if (!$this->track_late_checkin) {
            return false;
        }

        $gracePeriodEnd = $this->getGracePeriodEndTime();

        // Late if checked in AFTER grace period end time
        return $checkInTime->greaterThan($gracePeriodEnd);
    }

    /**
     * Calculate minutes late (from expected shift start, not grace period end)
     */
    public function getMinutesLate(Carbon $checkInTime): int
    {
        $shiftStart = Carbon::parse($this->start_time);

        // If checked in before or at shift start, not late
        if ($checkInTime->lessThanOrEqualTo($shiftStart)) {
            return 0;
        }

        // Return minutes late from shift start time
        return $shiftStart->diffInMinutes($checkInTime);

    }

    /**
     * Check if checkout is early (before threshold)
     */
    public function isEarlyCheckOut(Carbon $checkOutTime): bool
    {
        if (!$this->track_early_checkout) {
            return false;
        }


        $earlyThreshold = $this->getEarlyCheckoutThreshold();


        // Early if checked out BEFORE the threshold time
        return $checkOutTime->lessThan($earlyThreshold);
    }

    /**
     * Calculate minutes early (from expected shift end)
     */
    public function getMinutesEarly(Carbon $checkOutTime): int
    {
        // Build a shift end datetime using the same date as the check-out
        $shiftEnd = $checkOutTime->copy()->setTimeFromTimeString($this->end_time);

        // If checked out at or after shift end → not early
        if ($checkOutTime->greaterThanOrEqualTo($shiftEnd)) {
            return 0;
        }

        // Return positive minutes early
        return $checkOutTime->diffInMinutes($shiftEnd);
    }


}
