<?php

namespace App\Models;

use App\Helpers\QRCodeGenerator;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    use HasFactory;

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
        'active',
        'face_id',
        'shift_id', // Keep for backward compatibility
        'current_shift_id',
        'last_shift_change_at',
        'shift_change_cooldown_minutes',
        'qr_code',
        'employee_title',
        'shift_status',
        'start_off_shift_date',
        'end_off_shift_date',
    ];

    protected $casts = [
        'last_shift_change_at' => 'datetime',
        'active' => 'boolean',
    ];

    // ========================================
    // BOOT METHOD
    // ========================================
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

    // ========================================
    // RELATIONSHIPS - BASIC
    // ========================================
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function employeeType(): BelongsTo
    {
        return $this->belongsTo(EmployeeType::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    // ========================================
    // RELATIONSHIPS - SHIFT (MULTI-SHIFT SUPPORT)
    // ========================================

    /**
     * Legacy single shift (for backward compatibility)
     * This ensures existing code doesn't break
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * All shifts assigned to this employee
     */
    public function shifts(): BelongsToMany
    {
        return $this->belongsToMany(Shift::class, 'employee_shift_assignments')
            ->withPivot(['priority', 'is_active', 'effective_from', 'effective_until'])
            ->withTimestamps()
            ->orderByPivot('priority', 'desc');
    }

    /**
     * Only active shift assignments
     */
    public function activeShifts(): BelongsToMany
    {
        return $this->shifts()
            ->wherePivot('is_active', true)
            ->where(function ($query) {
                $query->whereNull('employee_shift_assignments.effective_from')
                    ->orWhere('employee_shift_assignments.effective_from', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('employee_shift_assignments.effective_until')
                    ->orWhere('employee_shift_assignments.effective_until', '>=', now());
            });
    }

    /**
     * Current active shift
     */
    public function currentShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'current_shift_id');
    }

    // ========================================
    // RELATIONSHIPS - ATTENDANCE & TIME
    // ========================================
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function latestAttendance(): HasOne
    {
        return $this->hasOne(Attendance::class)->latestOfMany();
    }

    public function overtimes(): HasMany
    {
        return $this->hasMany(Overtime::class);
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(Leave::class);
    }

    // ========================================
    // RELATIONSHIPS - WORK LOCATIONS
    // ========================================
    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeAssignment::class);
    }

    public function currentAssignment(): HasMany
    {
        return $this->hasMany(EmployeeAssignment::class)->where('is_current', true);
    }

    // ========================================
    // RELATIONSHIPS - OTHER
    // ========================================
    public function serviceUsages(): HasMany
    {
        return $this->hasMany(ServiceUsage::class);
    }

    // ========================================
    // ACCESSORS & ATTRIBUTES
    // ========================================
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

                // 2. Check for Sick Off Status
                if ($this->shift_status === 'sick_off' &&
                    $this->start_off_shift_date <= $today &&
                    $this->end_off_shift_date >= $today) {
                    return '<span class="badge border border-primary text-primary fs-1 fw-bold p-2 bg-transparent">🤒 SICK OFF</span>';
                }

                // 3. Check for Active Approved/Pending Leave
                $activeLeave = $this->leaves()
                    ->whereIn('status', ['approved', 'pending'])
                    ->where('start_date', '<=', $today)
                    ->where('end_date', '>=', $today)
                    ->first();

                if ($activeLeave) {
                    $title = htmlspecialchars($activeLeave->leave_type);
                    return "<span class='badge border border-primary text-primary fw-bold p-2 fs-1 bg-transparent' title='{$title}'>📅 ON LEAVE</span>";
                }

                return null;
            },
        );
    }

    // ========================================
    // HELPER METHODS - WORKED HOURS
    // ========================================
    public function weeklyWorkedHours($employeeId = null)
    {
        $employeeId = $employeeId ?? $this->id;

        $startOfWeek = Carbon::now()->startOfWeek();
        $endOfWeek = Carbon::now()->endOfWeek();

        return \DB::table('attendances')
            ->where('employee_id', $employeeId)
            ->whereBetween('date', [$startOfWeek, $endOfWeek])
            ->sum('worked_hours');
    }

    public function monthlyWorkedHours($employeeId = null)
    {
        $employeeId = $employeeId ?? $this->id;

        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        return \DB::table('attendances')
            ->where('employee_id', $employeeId)
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->sum('worked_hours');
    }

    public function weeklyOvertimeHours($employeeId = null)
    {
        $employeeId = $employeeId ?? $this->id;

        $startOfWeek = Carbon::now()->startOfWeek();
        $endOfWeek = Carbon::now()->endOfWeek();

        return \DB::table('attendances')
            ->where('employee_id', $employeeId)
            ->whereBetween('date', [$startOfWeek, $endOfWeek])
            ->sum('overtime_hours');
    }

    // ========================================
    // MULTI-SHIFT DETECTION
    // ========================================

    /**
     * Detect the best matching shift for a given time
     */
    public function detectShiftForTime(Carbon $checkInTime): ?array
    {
        $detectionLog = [
            'check_in_time' => $checkInTime->toDateTimeString(),
            'candidates' => [],
            'selected_shift' => null,
            'reason' => null,
        ];

        // Get all active shifts for this employee
        $activeShifts = $this->activeShifts()->get();

        if ($activeShifts->isEmpty()) {
            $detectionLog['reason'] = 'No active shifts assigned';
            return [
                'shift' => null,
                'auto_detected' => false,
                'method' => 'none',  // ADD THIS
                'log' => $detectionLog,
            ];
        }

        // If only one shift, auto-select it
        if ($activeShifts->count() === 1) {
            $singleShift = $activeShifts->first();
            $detectionLog['selected_shift'] = [
                'id' => $singleShift->id,
                'name' => $singleShift->name,
            ];
            $detectionLog['reason'] = 'Only one shift assigned, auto-selected';

            return [
                'shift' => $singleShift,
                'auto_detected' => true,
                'method' => 'single_shift',  // ADD THIS
                'score' => 100,
                'log' => $detectionLog,
            ];
        }

        $dayOfWeek = $checkInTime->format('D'); // Mon, Tue, etc.
        $checkInTimeOnly = $checkInTime->format('H:i:s');
        $candidates = [];

        foreach ($activeShifts as $shift) {
            $candidate = [
                'shift_id' => $shift->id,
                'shift_name' => $shift->name,
                'priority' => $shift->pivot->priority,
                'matches' => [],
                'score' => 0,
            ];

            // Check 1: Day pattern match
            $dayMatch = $this->isShiftScheduledForDay($shift, $dayOfWeek);
            $candidate['matches']['day_pattern'] = $dayMatch;
            if ($dayMatch) {
                $candidate['score'] += 40; // 40 points for day match
            }

            // Check 2: Time proximity
            $timeProximity = $this->calculateTimeProximity($shift, $checkInTimeOnly);
            $candidate['matches']['time_proximity'] = $timeProximity;
            $candidate['score'] += $timeProximity['score'];

            // Check 3: Grace period match
            if ($shift->grace_period_enabled) {
                $withinGrace = $shift->isWithinGracePeriod($checkInTime);
                $candidate['matches']['within_grace_period'] = $withinGrace;
                if ($withinGrace) {
                    $candidate['score'] += 20; // 20 bonus points
                }
            }

            // Check 4: Priority weight
            $candidate['score'] += $shift->pivot->priority * 5; // 5 points per priority level

            $candidates[] = $candidate;
            $detectionLog['candidates'][] = $candidate;
        }

        // Sort by score (highest first)
        usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);

        // Select the best match (if score > threshold)
        $bestCandidate = $candidates[0] ?? null;
        $minimumScore = 40; // Require at least day match or good time proximity

        if ($bestCandidate && $bestCandidate['score'] >= $minimumScore) {
            $selectedShift = $activeShifts->firstWhere('id', $bestCandidate['shift_id']);

            $detectionLog['selected_shift'] = [
                'id' => $selectedShift->id,
                'name' => $selectedShift->name,
                'score' => $bestCandidate['score'],
            ];
            $detectionLog['reason'] = 'Best matching shift selected based on score';

            return [
                'shift' => $selectedShift,
                'auto_detected' => true,
                'method' => 'auto_detection',  // ADD THIS
                'score' => $bestCandidate['score'],
                'log' => $detectionLog,
            ];
        }

        // No good match found
        $detectionLog['reason'] = 'No shift scored above minimum threshold';
        return [
            'shift' => null,
            'auto_detected' => false,
            'method' => 'failed',  // ADD THIS
            'log' => $detectionLog,
        ];
    }

    /**
     * Check if shift is scheduled for given day
     */
    private function isShiftScheduledForDay(Shift $shift, string $dayAbbr): bool
    {
        $patternDays = $shift->pattern_days ?? [];

        return match ($shift->pattern_type) {
            'weekdays' => in_array($dayAbbr, ['Mon', 'Tue', 'Wed', 'Thu', 'Fri']),
            'weekends' => in_array($dayAbbr, ['Sat', 'Sun']),
            'daily' => true,
            'custom', 'rotating' => in_array($dayAbbr, $patternDays),
            default => in_array($dayAbbr, $patternDays),
        };
    }

    /**
     * Calculate how close the check-in time is to shift start
     */
    private function calculateTimeProximity(Shift $shift, string $checkInTime): array
    {
        $shiftStart = Carbon::parse($shift->start_time);
        $checkIn = Carbon::parse($checkInTime);

        // Calculate minutes difference (absolute value)
        $minutesDiff = abs($shiftStart->diffInMinutes($checkIn, false));

        // MUCH MORE GENEROUS SCORING
        // 0 min diff = 60 points
        // 120 min diff (2 hours) = still 48 points
        // 240 min diff (4 hours) = still 36 points
        $score = max(0, 60 - ($minutesDiff * 0.1));

        return [
            'minutes_difference' => $minutesDiff,
            'score' => (int)$score,
            'shift_start' => $shiftStart->format('H:i'),
            'check_in' => $checkIn->format('H:i'),
        ];
    }

    // ========================================
    // SHIFT SWITCHING
    // ========================================

    /**
     * Check if employee can change shifts (cooldown check)
     */
    public function canChangeShift(): bool
    {
        if (!$this->last_shift_change_at) {
            return true;
        }

        $cooldownMinutes = $this->shift_change_cooldown_minutes ?? 240;
        $nextAllowedChange = $this->last_shift_change_at->addMinutes($cooldownMinutes);

        return now()->gte($nextAllowedChange);
    }

    /**
     * Get time until next shift change is allowed
     */
    public function getShiftChangeCooldownRemaining(): ?Carbon
    {
        if ($this->canChangeShift()) {
            return null;
        }

        $cooldownMinutes = $this->shift_change_cooldown_minutes ?? 240;
        return $this->last_shift_change_at->copy()->addMinutes($cooldownMinutes);
    }

    /**
     * Switch to a different shift
     */
    public function switchToShift(Shift $shift, bool $forceOverrideCooldown = false): bool
    {
        // Check if shift is assigned to employee
        if (!$this->activeShifts()->where('shifts.id', $shift->id)->exists()) {
            return false;
        }

        // Check cooldown
        if (!$forceOverrideCooldown && !$this->canChangeShift()) {
            return false;
        }

        $this->current_shift_id = $shift->id;
        $this->last_shift_change_at = now();
        $this->save();

        return true;
    }
}
