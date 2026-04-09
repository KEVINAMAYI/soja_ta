<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpecialActivity extends Model
{
    protected $fillable = [
        'organization_id', 'name', 'type', 'destination',
        'activity_date', 'departure_time', 'return_time',
        'emergency_contact', 'eligible_grades', 'lead_staff',
        'transport', 'notes', 'created_by',
    ];

    protected $casts = [
        'eligible_grades' => 'array',
        'activity_date'   => 'date',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(SpecialActivityParticipant::class);
    }

    /**
     * Is this activity "live" right now?
     */
    public function isLive(): bool
    {
        $now = now();
        return $this->activity_date->isToday()
            && $now->format('H:i') >= $this->departure_time
            && $now->format('H:i') <= $this->return_time;
    }

    /**
     * Find the live activity (if any) that an employee is a participant of.
     * Called from the ZKBio processing job.
     */
    public static function findLiveForEmployee(int $employeeId, int $orgId): ?self
    {
        $now = now();

        return self::where('organization_id', $orgId)
            ->where('activity_date', $now->toDateString())
            ->where('departure_time', '<=', $now->format('H:i'))
            ->where('return_time', '>=', $now->format('H:i'))
            ->whereHas('participants', fn($q) => $q->where('employee_id', $employeeId))
            ->first();
    }
}
