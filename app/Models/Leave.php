<?php

namespace App\Models;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Leave extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'department_id',
        'organization_id',
        'leave_type',
        'status',
        'start_date',
        'end_date',
        'reason',
        'contact_during_leave',
        'emergency_contact',
        'handover_to',
        'expected_resumption',
        'num_of_days',
        'leave_days',
        'elevatehr_reference',
        'elevatehr_event_id',
        'elevatehr_sequence',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'expected_resumption' => 'date',
        'num_of_days' => 'decimal:2',
        'leave_days' => 'array',
        'elevatehr_sequence' => 'integer',
    ];

    public function daysInRange(string|DateTimeInterface $start, string|DateTimeInterface $end): float
    {
        $start = Carbon::parse($start)->toDateString();
        $end = Carbon::parse($end)->toDateString();

        if ($this->leave_days !== null) {
            return (float) collect($this->leave_days)
                ->filter(fn ($day) => $day['date'] >= $start && $day['date'] <= $end && ! $day['is_holiday'])
                ->sum(fn ($day) => $day['day_part'] === 'FULL_DAY' ? 1 : 0.5);
        }

        $from = $this->start_date->max($start);
        $to = $this->end_date->min($end);

        return $from->gt($to) ? 0 : $from->diffInDays($to) + 1;
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }
}
