<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Shift extends Model
{
    use HasFactory;

    protected $table = 'shifts';

    protected $fillable = [
        // existing
        'organization_id', 'name', 'start_time', 'end_time', 'duration_hours',
        'break_minutes', 'overtime_rate', 'overtime_enabled', 'max_overtime_hours',
        'auto_clock_out', 'warning_time_minutes', 'pattern_type', 'pattern_days',
        'notify_managers_overtime', 'employee_mobile_notifications', 'email_summaries',
        'status', 'notes', 'grace_period_enabled', 'grace_period_minutes',
        'track_late_checkin', 'notify_on_late_checkin', 'track_early_checkout',
        'early_checkout_threshold_minutes',
        // NEW
        'department_type', 'shift_type', 'friday_end_time',
        'is_overnight', 'overtime_saturday', 'overtime_sunday',
    ];

    protected $casts = [
        // existing
        'start_time' => 'datetime:H:i', 'end_time' => 'datetime:H:i',
        'duration_hours' => 'decimal:2', 'max_overtime_hours' => 'decimal:2',
        'warning_time_minutes' => 'integer', 'overtime_enabled' => 'boolean',
        'auto_clock_out' => 'boolean', 'notify_managers_overtime' => 'boolean',
        'employee_mobile_notifications' => 'boolean', 'email_summaries' => 'boolean',
        'pattern_days' => 'array', 'grace_period_enabled' => 'boolean',
        'track_late_checkin' => 'boolean', 'notify_on_late_checkin' => 'boolean',
        'track_early_checkout' => 'boolean', 'grace_period_minutes' => 'integer',
        'early_checkout_threshold_minutes' => 'integer',
        // NEW
        'is_overnight' => 'boolean',
    ];

    protected $attributes = [
        // existing
        'duration_hours' => 8.00, 'overtime_enabled' => true,
        'max_overtime_hours' => 2.00, 'auto_clock_out' => false,
        'warning_time_minutes' => 30, 'pattern_type' => 'weekdays',
        'notify_managers_overtime' => false, 'employee_mobile_notifications' => true,
        'email_summaries' => false, 'grace_period_enabled' => true,
        'grace_period_minutes' => 15, 'track_late_checkin' => true,
        'notify_on_late_checkin' => false, 'track_early_checkout' => true,
        'early_checkout_threshold_minutes' => 15,
        // NEW
        'is_overnight' => false, 'overtime_saturday' => 'ot1', 'overtime_sunday' => 'ot2',
    ];

    // =========================================================================
    // EXISTING relationships & methods — ALL KEPT EXACTLY AS-IS
    // =========================================================================

    public function breaks() { return $this->hasMany(ShiftBreak::class)->ordered(); }
    public function activeBreaks() { return $this->hasMany(ShiftBreak::class)->active()->ordered(); }
    public function mandatoryBreaks() { return $this->hasMany(ShiftBreak::class)->active()->mandatory()->ordered(); }
    public function organization() { return $this->belongsTo(Organization::class); }
    public function employees()
    {
        return $this->belongsToMany(Employee::class, 'employee_shifts')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function getTotalBreakMinutes(): int
    {
        return $this->activeBreaks()->where('type', '!=', 'paid')->sum('duration_minutes');
    }

    public function getTotalPaidBreakMinutes(): int
    {
        return $this->activeBreaks()->where('type', 'paid')->sum('duration_minutes');
    }

    public function getEffectiveWorkingHours(): float
    {
        if ($this->start_time && $this->end_time) {
            try {
                $baseDate = now()->startOfDay();
                $start = $baseDate->copy()->setTime($this->start_time->hour, $this->start_time->minute, $this->start_time->second);
                $end   = $baseDate->copy()->setTime($this->end_time->hour, $this->end_time->minute, $this->end_time->second);
                if ($end->lt($start)) $end->addDay();
                $workingMinutes = max(0, $start->diffInMinutes($end) - $this->getTotalBreakMinutes());
                return round($workingMinutes / 60, 2);
            } catch (\Exception $e) { return 0; }
        }
        return 0;
    }

    public function initializeBreakLogs(Attendance $attendance): void
    {
        foreach ($this->activeBreaks as $break) {
            AttendanceBreakLog::create(['attendance_id' => $attendance->id, 'shift_break_id' => $break->id, 'status' => 'pending', 'is_taken' => false, 'is_compliant' => true]);
        }
    }

    public function areAllMandatoryBreaksCompleted(Attendance $attendance): bool
    {
        $ids = $this->mandatoryBreaks()->pluck('id');
        return AttendanceBreakLog::where('attendance_id', $attendance->id)->whereIn('shift_break_id', $ids)->whereIn('status', ['completed', 'exceeded'])->count() === $ids->count();
    }

    public function getNextScheduledBreak(?Carbon $currentTime = null): ?ShiftBreak
    {
        $currentTime = $currentTime ?? now();
        return $this->activeBreaks()->where('window_start_time', '>', $currentTime->format('H:i:s'))->first();
    }

    public function getCurrentAvailableBreak(?Carbon $currentTime = null): ?ShiftBreak
    {
        $currentTime = $currentTime ?? now();
        foreach ($this->activeBreaks as $break) { if ($break->isWithinWindow($currentTime)) return $break; }
        return null;
    }

    public function getDurationAttribute(): ?float { return $this->getEffectiveWorkingHours(); }

    public function getPatternDisplayAttribute(): string
    {
        $patterns = ['weekdays' => 'Weekdays Only', 'weekends' => 'Weekends Only', 'daily' => 'Daily', 'rotating' => 'Rotating Schedule', 'custom' => 'Custom Days'];
        $name = $patterns[$this->pattern_type] ?? 'Custom';
        if (in_array($this->pattern_type, ['custom', 'rotating']) && $this->pattern_days) return $name . ' (' . implode(', ', $this->pattern_days) . ')';
        return $name;
    }

    public function getEmployeeCountAttribute(): int { return $this->employees()->where('active', true)->count(); }

    public function getAutoClockOutTimeAttribute(): ?Carbon
    {
        if (!$this->auto_clock_out) return null;
        $end = Carbon::parse($this->end_time);
        return $this->overtime_enabled ? $end->addHours($this->max_overtime_hours) : $end;
    }

    public function getWarningTimeAttribute(): ?Carbon
    {
        if (!$this->auto_clock_out) return null;
        return $this->getAutoClockOutTimeAttribute()?->copy()->subMinutes($this->warning_time_minutes);
    }

    public function isActiveOnDay(string $dayAbbreviation): bool { return in_array($dayAbbreviation, $this->pattern_days ?? []); }
    public function scopeActive($query) { return $query->where('status', 'active'); }
    public function scopeByPattern($query, string $pattern) { return $query->where('pattern_type', $pattern); }

    public function getGracePeriodEndTime(): Carbon
    {
        $time = Carbon::parse($this->getRawOriginal('start_time'))->format('H:i:s');
        return Carbon::parse($time)->addMinutes(
            $this->grace_period_enabled ? $this->grace_period_minutes : 0
        );
    }

    public function getEarlyCheckoutThreshold(): Carbon
    {
        $time = Carbon::parse($this->getRawOriginal('end_time'))->format('H:i:s');
        return Carbon::parse($time)->subMinutes(
            $this->track_early_checkout ? $this->early_checkout_threshold_minutes : 0
        );
    }

    public function isWithinGracePeriod(Carbon $checkInTime): bool
    {
        if (!$this->grace_period_enabled) return false;
        return $checkInTime->greaterThan(Carbon::parse($this->start_time)) && $checkInTime->lessThanOrEqualTo($this->getGracePeriodEndTime());
    }

    public function isLateCheckIn(Carbon $checkInTime): bool
    {
        if (!$this->track_late_checkin) return false;
        return $checkInTime->greaterThan($this->getGracePeriodEndTime());
    }

    public function getMinutesLate(Carbon $checkInTime): int
    {
        $shiftStart = Carbon::parse($this->start_time);
        return $checkInTime->lessThanOrEqualTo($shiftStart) ? 0 : $shiftStart->diffInMinutes($checkInTime);
    }

    public function isEarlyCheckOut(Carbon $checkOutTime): bool
    {
        if (!$this->track_early_checkout) return false;
        return $checkOutTime->lessThan($this->getEarlyCheckoutThreshold());
    }

    public function getMinutesEarly(Carbon $checkOutTime): int
    {
        $shiftEnd = $checkOutTime->copy()->setTimeFromTimeString($this->end_time);
        return $checkOutTime->greaterThanOrEqualTo($shiftEnd) ? 0 : $checkOutTime->diffInMinutes($shiftEnd);
    }

    // =========================================================================
    // NEW METHODS — client shift requirements
    // =========================================================================

    /**
     * Start time anchored to a real date string.
     * Fixes overnight comparisons when date is not today.
     */
    public function getEffectiveStartTime(string $date): Carbon
    {
        $time = Carbon::parse($this->getRawOriginal('start_time'))->format('H:i:s');
        return Carbon::parse($date . ' ' . $time);
    }

    public function getEffectiveEndTime(string $date): Carbon
    {
        $carbon    = Carbon::parse($date);
        $isFriday  = $carbon->format('l') === 'Friday';
        $isWeekend = in_array($carbon->format('l'), ['Saturday', 'Sunday']);

        $endHis    = substr($this->attributes['end_time'] ?? '17:30:00', 0, 8);
        $startHis  = substr($this->attributes['start_time'] ?? '08:00:00', 0, 8);
        $fridayHis = !empty($this->attributes['friday_end_time'])
            ? substr($this->attributes['friday_end_time'], 0, 8)
            : null;

        $useHis = (($isFriday || $isWeekend) && $fridayHis) ? $fridayHis : $endHis;

        $end   = Carbon::parse($date)->setTimeFromTimeString($useHis);
        $start = Carbon::parse($date)->setTimeFromTimeString($startHis);

        if ($end->lte($start)) $end->addDay();

        return $end;
    }

    public function getGraceDeadline(string $date): Carbon
    {
        $grace = $this->grace_period_enabled ? ($this->grace_period_minutes ?? 0) : 0;
        return $this->getEffectiveStartTime($date)->addMinutes($grace);
    }

    public function isOvernightShift(): bool
    {
        if ($this->is_overnight) return true;
        $start = Carbon::parse($this->getRawOriginal('start_time'))->format('H:i:s');
        $end   = Carbon::parse($this->getRawOriginal('end_time'))->format('H:i:s');
        return Carbon::parse($end)->lte(Carbon::parse($start));
    }

    /**
     * Is this shift scheduled on a given calendar date?
     */
    public function isScheduledOn(string $date): bool
    {
        $carbon    = Carbon::parse($date);
        $dayOfWeek = $carbon->format('D'); // Mon, Tue, etc.
        $days      = $this->pattern_days ?? [];

        return in_array($dayOfWeek, $days);
    }

    /**
     * OT tier for a given date.
     * Saturday → OT1 | Sunday → OT2 | Weekday → Weekday OT | null = OT disabled
     */
    public function getOvertimeTier(string $date): ?string
    {
        if (!$this->overtime_enabled) return null;
        return match (Carbon::parse($date)->dayOfWeek) {
            Carbon::SATURDAY => 'OT1',
            Carbon::SUNDAY   => 'OT2',
            default          => 'Weekday OT',
        };
    }

    /** T&A report: Staff Category column */
    public function getDepartmentLabel(): string
    {
        return match ($this->department_type) {
            'admin'       => 'Admin',
            'general'     => 'General',
            'engineering' => 'Engineering',
            default       => ucfirst($this->department_type ?? 'Unknown'),
        };
    }

    /** T&A report: Shift (Day/Night) column */
    public function getShiftTypeLabel(): string
    {
        return match ($this->shift_type) {
            'day'      => 'Day',
            'night'    => 'Night',
            'extended' => 'Extended',
            'admin'    => 'Admin',
            default    => ucfirst($this->shift_type ?? 'Unknown'),
        };
    }

    /** Full label for UI dropdowns and shift cards */
    public function getFullLabel(): string
    {
        $label = $this->getDepartmentLabel() . ' — ' . $this->getShiftTypeLabel() . ' Shift';
        if ($this->friday_end_time && $this->friday_end_time !== $this->end_time && !$this->isOvernightShift()) {
            $label .= ' (Fri ends ' . Carbon::parse($this->friday_end_time)->format('H:i') . ')';
        }
        if ($this->isOvernightShift()) $label .= ' 🌙';
        return $label;
    }

    /** Hex colour for sidebar badge */
    public function getDepartmentColour(): string
    {
        return match ($this->department_type) {
            'admin'       => '1565C0',
            'general'     => '2E7D32',
            'engineering' => '6A1B9A',
            default       => '546E7A',
        };
    }

    public function scopeForDepartment($query, string $dept) { return $query->where('department_type', $dept); }
    public function scopeForOrganization($query, int $orgId) { return $query->where('organization_id', $orgId); }
}
