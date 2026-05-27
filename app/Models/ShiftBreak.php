<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;

class ShiftBreak extends Model
{
    use HasFactory;

    protected $fillable = [
        'shift_id',
        'name',
        'type',
        'window_start_time',
        'window_end_time',
        'duration_minutes',
        'max_duration_minutes',
        'penalty_type',
        'require_punch',
        'notify_on_approaching',
        'notify_minutes_before',
        'is_mandatory',
        'order',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'window_start_time' => 'datetime:H:i',
        'window_end_time' => 'datetime:H:i',
        'duration_minutes' => 'integer',
        'max_duration_minutes' => 'integer',
        'notify_minutes_before' => 'integer',
        'require_punch' => 'boolean',
        'notify_on_approaching' => 'boolean',
        'is_mandatory' => 'boolean',
        'is_active' => 'boolean',
        'order' => 'integer',
        'metadata' => 'array',
    ];

    /**
     * ========================================
     * RELATIONSHIPS
     * ========================================
     */

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function breakLogs()
    {
        return $this->hasMany(AttendanceBreakLog::class);
    }

    /**
     * ========================================
     * SCOPES
     * ========================================
     */

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order')->orderBy('window_start_time');
    }

    public function scopeMandatory($query)
    {
        return $query->where('is_mandatory', true);
    }

    /**
     * ========================================
     * HELPER METHODS
     * ========================================
     */

    /**
     * Check if current time is within the break window
     */
    public function isWithinWindow(?Carbon $time = null): bool
    {
        $time = $time ?? now();

        if (!$this->window_start_time || !$this->window_end_time) {
            return true; // No window restriction
        }

        $windowStart = Carbon::parse($this->window_start_time);
        $windowEnd = Carbon::parse($this->window_end_time);

        // Handle overnight windows
        if ($windowEnd->lt($windowStart)) {
            $windowEnd->addDay();
        }

        $checkTime = $time->copy()->setTimeFromTimeString($time->format('H:i:s'));

        return $checkTime->between($windowStart, $windowEnd);
    }

    /**
     * Calculate if break duration exceeds allowed time
     */
    public function calculateExcessMinutes(int $actualMinutes): int
    {
        $maxAllowed = $this->max_duration_minutes ?? $this->duration_minutes;
        return max(0, $actualMinutes - $maxAllowed);
    }

    /**
     * Check if break duration is compliant
     */
    public function isCompliant(int $actualMinutes): bool
    {
        return $this->calculateExcessMinutes($actualMinutes) === 0;
    }

    /**
     * Get break duration in hours
     */
    public function getDurationHours(): float
    {
        return round($this->duration_minutes / 60, 2);
    }

    /**
     * Get penalty description
     */
    public function getPenaltyDescription(): string
    {
        return match($this->penalty_type) {
            'deduct_overtime' => 'Deduct overtime minutes',
            'flag_review' => 'Flag for manager review',
            'auto_deduct' => 'Auto-deduct from working hours',
            default => 'No penalty',
        };
    }

    /**
     * Get type label
     */
    public function getTypeLabel(): string
    {
        return match($this->type) {
            'paid' => 'Paid Break',
            'unpaid' => 'Unpaid Break',
            'flexible' => 'Flexible Break',
            default => ucfirst($this->type),
        };
    }

    /**
     * Get formatted window time
     */
    public function getWindowTimeFormatted(): ?string
    {
        if (!$this->window_start_time || !$this->window_end_time) {
            return null;
        }

        return Carbon::parse($this->window_start_time)->format('h:i A') . ' - ' .
            Carbon::parse($this->window_end_time)->format('h:i A');
    }

    /**
     * Check if this is a paid break (counts toward working hours)
     */
    public function isPaid(): bool
    {
        return $this->type === 'paid';
    }

    /**
     * Apply penalty to attendance record
     */
    public function applyPenalty(AttendanceBreakLog $breakLog): void
    {
        if ($this->penalty_type === 'none' || $breakLog->is_compliant) {
            return;
        }

        $attendance = $breakLog->attendance;
        $excessMinutes = $breakLog->excess_minutes;

        switch ($this->penalty_type) {
            case 'deduct_overtime':
                // Deduct from overtime hours first
                $excessHours = round($excessMinutes / 60, 2);
                if ($attendance->overtime_hours >= $excessHours) {
                    $attendance->overtime_hours -= $excessHours;
                    $attendance->save();
                }
                break;

            case 'auto_deduct':
                // Deduct from total worked hours
                $excessHours = round($excessMinutes / 60, 2);
                $attendance->worked_hours = max(0, $attendance->worked_hours - $excessHours);
                $attendance->save();
                break;

            case 'flag_review':
                // Just flag - no automatic deduction
                // This can be handled in the attendance status
                break;
        }
    }
}
