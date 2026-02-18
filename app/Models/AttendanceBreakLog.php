<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;

class AttendanceBreakLog extends Model
{

    protected $fillable = [
        'attendance_id',
        'shift_break_id',
        'break_start_time',
        'break_end_time',
        'actual_duration_minutes',
        'excess_minutes',
        'is_compliant',
        'is_taken',
        'status',
        'notes',
        'is_auto_detected'
    ];

    protected $casts = [
        'break_start_time' => 'datetime',
        'break_end_time' => 'datetime',
        'actual_duration_minutes' => 'integer',
        'excess_minutes' => 'integer',
        'is_compliant' => 'boolean',
        'is_taken' => 'boolean',
    ];

    /**
     * ========================================
     * RELATIONSHIPS
     * ========================================
     */

    public function attendance()
    {
        return $this->belongsTo(Attendance::class);
    }

    public function shiftBreak()
    {
        return $this->belongsTo(ShiftBreak::class);
    }

    /**
     * ========================================
     * SCOPES
     * ========================================
     */

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeExceeded($query)
    {
        return $query->where('status', 'exceeded');
    }

    public function scopeNonCompliant($query)
    {
        return $query->where('is_compliant', false);
    }

    /**
     * ========================================
     * HELPER METHODS
     * ========================================
     */

    /**
     * Start a break
     */
    public function startBreak(?Carbon $time = null): void
    {
        $this->update([
            'break_start_time' => $time ?? now(),
            'status' => 'in_progress',
        ]);
    }

    /**
     * End a break and calculate duration
     */
    public function endBreak(?Carbon $time = null): void
    {
        $endTime = $time ?? now();

        if (!$this->break_start_time) {
            throw new \Exception('Break has not been started');
        }

        $startTime = Carbon::parse($this->break_start_time);
        $actualMinutes = $startTime->diffInMinutes($endTime);

        $excessMinutes = $this->shiftBreak->calculateExcessMinutes($actualMinutes);
        $isCompliant = $excessMinutes === 0;

        $this->update([
            'break_end_time' => $endTime,
            'actual_duration_minutes' => $actualMinutes,
            'excess_minutes' => $excessMinutes,
            'is_compliant' => $isCompliant,
            'is_taken' => true,
            'status' => $isCompliant ? 'completed' : 'exceeded',
        ]);

        // Apply penalty if configured
        if (!$isCompliant) {
            $this->shiftBreak->applyPenalty($this);
        }
    }

    /**
     * Mark break as skipped
     */
    public function markAsSkipped(string $reason = null): void
    {
        $this->update([
            'status' => 'skipped',
            'is_taken' => false,
            'notes' => $reason,
        ]);
    }

    /**
     * Get duration in hours
     */
    public function getDurationHours(): float
    {
        return round(($this->actual_duration_minutes ?? 0) / 60, 2);
    }

    /**
     * Get excess duration in hours
     */
    public function getExcessHours(): float
    {
        return round($this->excess_minutes / 60, 2);
    }

    /**
     * Check if break is currently in progress
     */
    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }

    /**
     * Get status label
     */
    public function getStatusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Not Started',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            'skipped' => 'Skipped',
            'exceeded' => 'Exceeded Limit',
            default => ucfirst($this->status),
        };
    }

    /**
     * Get status color for UI
     */
    public function getStatusColor(): string
    {
        return match ($this->status) {
            'pending' => 'secondary',
            'in_progress' => 'primary',
            'completed' => 'success',
            'skipped' => 'warning',
            'exceeded' => 'danger',
            default => 'secondary',
        };
    }
}
