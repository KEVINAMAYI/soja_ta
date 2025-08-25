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
        'name',
        'start_time',
        'end_time',
        'break_minutes',
        'overtime_rate',
        'status',
        'notes',
        'organization_id',
    ];

    // Casts for correct data types
    protected $casts = [
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'break_minutes' => 'integer',
        'overtime_rate' => 'decimal:2',
    ];

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

}
