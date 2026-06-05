<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo; // ← this was missing

class SpecialActivityParticipant extends Model
{
    protected $fillable = [
        'special_activity_id', 'employee_id', 'status',
        'departed_at', 'returned_at',
    ];

    protected $casts = [
        'departed_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(SpecialActivity::class, 'special_activity_id');
    }

    /**
     * ══════════════════════════════════════════════════════════════
     *  BIOMETRIC HOOK — called from ProcessZKBioTransactions job
     * ══════════════════════════════════════════════════════════════
     *
     * When ZKBio sends a scan for an employee:
     *
     *   1. Check if the employee is a participant in a LIVE activity today.
     *   2. If YES → update the participant's status (departed/returned)
     *      instead of the normal attendance clocked_in/clocked_out flow.
     *   3. If NO  → fall through to the existing Attendance logic.
     *
     * @param int    $employeeId
     * @param int    $orgId
     * @param string $scanType   'clocked_in' | 'clocked_out'  (from your ZKBio logic)
     * @return bool  true = scan was handled here, false = use normal attendance
     */
    public static function handleBiometricScan(int $employeeId, int $orgId, string $scanType): bool
    {
        $activity = SpecialActivity::findLiveForEmployee($employeeId, $orgId);

        if (!$activity) {
            return false; // Not on an activity — use normal attendance
        }

        $participant = self::where('special_activity_id', $activity->id)
            ->where('employee_id', $employeeId)
            ->first();

        if (!$participant) {
            return false;
        }

        /*
         * Scan logic:
         *
         *  clocked_out (gate exit scan) = Student is DEPARTING for the activity
         *  clocked_in  (gate entry scan) = Student has RETURNED from the activity
         *
         * This mirrors how the ZKBio biometric gate works:
         *   - Student leaves campus → gate exit scan → "departed"
         *   - Student returns to campus → gate entry scan → "returned"
         *
         * Adjust clocked_in/clocked_out mapping to match YOUR gate setup.
         */
        if ($scanType === 'clocked_out' && $participant->status === 'confirmed') {
            $participant->update([
                'status'      => 'departed',
                'departed_at' => now(),
            ]);
            return true;
        }

        if ($scanType === 'clocked_in' && $participant->status === 'departed') {
            $participant->update([
                'status'      => 'returned',
                'returned_at' => now(),
            ]);
            return true;
        }

        return true; // Student is on activity, swallow the scan
    }


}
