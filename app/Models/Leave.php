<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
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
        'leave_type_id',
        'status',
        'current_level',
        'total_levels',
        'start_date',
        'end_date',
        'reason',
        'contact_during_leave',
        'emergency_contact',
        'handover_to',
        'expected_resumption',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'expected_resumption' => 'date',
        'current_level' => 'integer',
        'total_levels' => 'integer',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function approvalLogs()
    {
        return $this->hasMany(LeaveApprovalLog::class);
    }

    public function activeApprovalLog()
    {
        // Deliberately NOT filtered by current_level here: only one approval
        // log is ever 'pending' per leave at a time (the previous level's log
        // is already closed out before the next one opens), so this alone is
        // sufficient. Referencing $this->current_level in this closure would
        // break under eager loading (->with('activeApprovalLog')), since the
        // constraint gets built once against an unhydrated model instance.
        return $this->hasOne(LeaveApprovalLog::class)->where('status', 'pending');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Count business days (Mon–Fri) between two dates, inclusive of both
     * endpoints regardless of which day of the week either one falls on.
     * This is the single source of truth for leave-day counts — balance
     * deduction, balance checks, and every UI that displays a day count
     * must all go through this so they can never disagree with each other.
     */
    public static function businessDaysBetween($startDate, $endDate): int
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();

        return $start->diffInDaysFiltered(
            fn (CarbonInterface $date) => $date->isWeekday(),
            $end->copy()->addDay()
        );
    }

    /**
     * Given a start date and a number of business days, find the end date
     * that covers exactly that many weekdays — skipping over any Saturday/
     * Sunday in between rather than counting them. E.g. 4 days starting on
     * a Thursday lands on the following Tuesday (Thu, Fri, Mon, Tue), not
     * Sunday. Mirrors businessDaysBetween() so a request for N days always
     * ends up with businessDaysBetween(start, end) === N.
     */
    public static function addBusinessDays($startDate, int $days): Carbon
    {
        $date = Carbon::parse($startDate)->startOfDay();
        $counted = $date->isWeekday() ? 1 : 0;

        while ($counted < $days) {
            $date->addDay();
            if ($date->isWeekday()) {
                $counted++;
            }
        }

        return $date;
    }

    /**
     * Approval-chain progress for API consumers: current/total level and
     * every level opened so far with its approver and outcome. Only reflects
     * levels actually opened (no placeholders for levels not yet reached).
     * Null when no approval chain was ever configured for this leave.
     *
     * Relies on approvalLogs (and its approverUser/actionedBy) being
     * eager-loaded by the caller to avoid N+1 queries.
     */
    public function getApprovalProgressAttribute(): ?array
    {
        if ($this->total_levels === null) {
            return null;
        }

        return [
            'enabled' => true,
            'current_level' => $this->current_level,
            'total_levels' => $this->total_levels,
            'levels' => $this->approvalLogs
                ->sortBy('level_number')
                ->map(fn (LeaveApprovalLog $log) => [
                    'level_number' => $log->level_number,
                    'status' => $log->status,
                    'approver_type' => $log->approver_type,
                    'approver_role' => $log->approver_role,
                    'approver_user' => $log->approverUser
                        ? ['id' => $log->approverUser->id, 'name' => $log->approverUser->name]
                        : null,
                    'actioned_by' => $log->actionedBy
                        ? ['id' => $log->actionedBy->id, 'name' => $log->actionedBy->name]
                        : null,
                    'opened_at' => $log->opened_at,
                    'closed_at' => $log->closed_at,
                    'notes' => $log->notes,
                ])->values()->all(),
        ];
    }
}
