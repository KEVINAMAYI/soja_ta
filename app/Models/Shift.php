<?php

namespace App\Models;

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
    public function getDurationAttribute()
    {
        if ($this->start_time && $this->end_time) {
            $start = \Carbon\Carbon::createFromFormat('H:i:s', $this->start_time);
            $end = \Carbon\Carbon::createFromFormat('H:i:s', $this->end_time);

            // Handle overnight shifts (end < start)
            if ($end->lt($start)) {
                $end->addDay();
            }

            $minutes = $end->diffInMinutes($start) - ($this->break_minutes ?? 0);
            return $minutes > 0 ? $minutes : 0;
        }

        return null;
    }


    public function organization()
    {
        $this->belongsTo(Organization::class);
    }

}
