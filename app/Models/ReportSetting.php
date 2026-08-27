<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ReportSetting extends Model
{
    use HasFactory;

    protected $table = 'report_settings';

    protected $fillable = [
        'organization_id',
        'email',
        'report_type',
        'format',
        'frequency',
        'time',
        'day_of_week',
        'timezone',
        'active',
        'last_run_at',
        'next_run_at',
    ];

    protected $casts = [
        'active' => 'boolean',
        'time' => 'datetime:H:i', // if stored as TIME in DB, Laravel will format
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */
    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }


    // Optional: If `email` always belongs to a User
    public function user()
    {
        return $this->belongsTo(User::class, 'email', 'email');
    }


    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeForOrganization($query, $orgId)
    {
        return $query->where('organization_id', $orgId);
    }

    public function scopeForReportType($query, $type)
    {
        return $query->where('report_type', $type);
    }

    /**
     * Next time this schedule is due, computed from a given instant (in the
     * schedule's own timezone). Shared by SendReportJob (after each run) and
     * schedule creation (so a brand-new schedule has next_run_at set right away,
     * instead of depending on hitting an exact H:i tick — see SendReportsCommand).
     */
    public function calculateNextRunFrom(Carbon $from): ?Carbon
    {
        $tzNow = $from->copy()->setTimezone($this->timezone ?? config('app.timezone'));

        switch ($this->frequency) {
            case 'daily':
                return $tzNow->addDay()->setTimeFromTimeString($this->time->format('H:i'));

            case 'weekly':
                $dayOfWeek = $this->day_of_week ?? 'Monday';
                return $tzNow->copy()->next($dayOfWeek)->setTimeFromTimeString($this->time->format('H:i'));

            case 'monthly':
                if ($this->day_of_week) {
                    $endOfMonth = $tzNow->copy()->endOfMonth();
                    $nextOccurrence = $endOfMonth->next($this->day_of_week)->setTimeFromTimeString($this->time->format('H:i'));
                    if ($nextOccurrence->month !== $tzNow->month) {
                        $nextOccurrence = $endOfMonth->copy()->addMonth()->endOfMonth()->setTimeFromTimeString($this->time->format('H:i'));
                    }
                    return $nextOccurrence;
                }
                return $tzNow->copy()->addMonth()->endOfMonth()->setTimeFromTimeString($this->time->format('H:i'));

            default:
                return null;
        }
    }
}
