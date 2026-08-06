<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveApprovalLog;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\LeaveApprovalRequiredNotification;
use Illuminate\Support\Facades\Notification;

class LeaveApprovalService
{
    /**
     * Kick off the approval chain for a newly created leave request.
     *
     * If the organization has no enabled approval chain, this is a no-op —
     * the leave stays 'pending' and must be actioned manually, exactly as
     * before this feature existed.
     */
    public function createApprovalChain(Leave $leave): ?LeaveApprovalLog
    {
        $settings = LeaveApprovalSettings::get($leave->organization_id, $leave->department_id);

        if (!$settings['enabled']) {
            return null;
        }

        $start = LeaveApprovalSettings::firstEnabledLevel($settings);
        if ($start === null) {
            return null;
        }

        $leave->total_levels = collect($settings['levels'])->where('enabled', true)->count();
        $leave->current_level = $start;
        $leave->save();

        return $this->openLevel($leave, $start, $settings);
    }

    /**
     * Open the approval log for the given level and notify its approver(s).
     * If the level turns out to be disabled (config changed mid-flight),
     * skip straight to the next one.
     */
    public function openLevel(Leave $leave, int $level, array $settings): ?LeaveApprovalLog
    {
        $config = $settings['levels'][$level - 1] ?? null;

        if (!$config || !$config['enabled']) {
            return $this->advanceOrFinalize($leave, $level, $settings);
        }

        $log = LeaveApprovalLog::create([
            'leave_id' => $leave->id,
            'level_number' => $level,
            'approver_type' => $config['approver_type'],
            'approver_role' => $config['approver_type'] === 'role' ? $config['approver_role'] : null,
            'approver_user_id' => $config['approver_type'] === 'user' ? $config['approver_user_id'] : null,
            'status' => 'pending',
            'opened_at' => now(),
        ]);

        $leave->current_level = $level;
        $leave->save();

        $this->sendNotifications($leave, $config, $level);

        return $log;
    }

    private function advanceOrFinalize(Leave $leave, int $fromLevel, array $settings, ?string $notes = null): ?LeaveApprovalLog
    {
        $next = LeaveApprovalSettings::nextEnabledLevel($settings, $fromLevel);

        if ($next !== null) {
            return $this->openLevel($leave, $next, $settings);
        }

        $this->finalizeApproval($leave);

        return null;
    }

    /**
     * Approve the currently active level for this leave, advancing to the
     * next enabled level or finalizing approval if this was the last one.
     */
    public function approve(Leave $leave, User $actor, ?string $notes = null): Leave
    {
        $activeLog = $leave->activeApprovalLog()->first();

        if (!$activeLog) {
            throw new \RuntimeException('No pending approval level found for this leave.');
        }

        $this->authorizeActor($actor, $activeLog);

        $activeLog->update([
            'status' => 'approved',
            'closed_at' => now(),
            'actioned_by' => $actor->id,
            'notes' => $notes,
        ]);

        $settings = LeaveApprovalSettings::get($leave->organization_id, $leave->department_id);
        $this->advanceOrFinalize($leave, $activeLog->level_number, $settings, $notes);

        return $leave->fresh();
    }

    /**
     * Reject the currently active level. A rejection at any level
     * immediately finalizes the leave as rejected — no further levels open.
     */
    public function reject(Leave $leave, User $actor, ?string $notes = null): Leave
    {
        $activeLog = $leave->activeApprovalLog()->first();

        if (!$activeLog) {
            throw new \RuntimeException('No pending approval level found for this leave.');
        }

        $this->authorizeActor($actor, $activeLog);

        $activeLog->update([
            'status' => 'rejected',
            'closed_at' => now(),
            'actioned_by' => $actor->id,
            'notes' => $notes,
        ]);

        $leave->status = 'rejected';
        $leave->save();

        return $leave->fresh();
    }

    private function authorizeActor(User $actor, LeaveApprovalLog $log): void
    {
        if (!$this->matchesApprover($actor, $log)) {
            throw new \RuntimeException('You are not authorized to action this approval level.');
        }
    }

    private function matchesApprover(User $actor, LeaveApprovalLog $log): bool
    {
        return $log->approver_type === 'user'
            ? $actor->id === $log->approver_user_id
            : ($log->approver_role && $actor->hasRole($log->approver_role));
    }

    /**
     * Whether $actor is the designated approver for $leave's currently active
     * level — i.e. whether they should see/be allowed to use the Approve/Reject
     * action right now. This is the single source of truth for both the admin
     * UI (button visibility) and the API (action authorization); it does NOT
     * depend on any blanket "approve-leave-requests" permission, since the
     * approval chain itself already specifies exactly who may act at each level.
     */
    public function canAct(Leave $leave, User $actor): bool
    {
        $activeLog = $leave->activeApprovalLog()->first();

        if (!$activeLog) {
            return false;
        }

        return $this->matchesApprover($actor, $activeLog);
    }

    /**
     * Whether $actor is the designated approver for ANY currently pending
     * leave in their organization — used to decide whether to surface the
     * "Leave Requests" navigation link to someone who otherwise holds no
     * broader leave-management permission.
     */
    public function hasAnyPendingApprovalFor(User $actor, int $organizationId): bool
    {
        $roleNames = $actor->getRoleNames();

        return LeaveApprovalLog::where('status', 'pending')
            ->whereHas('leave', fn ($q) => $q->where('organization_id', $organizationId))
            ->where(function ($q) use ($actor, $roleNames) {
                $q->where(fn ($q2) => $q2->where('approver_type', 'user')->where('approver_user_id', $actor->id))
                    ->orWhere(fn ($q2) => $q2->where('approver_type', 'role')->whereIn('approver_role', $roleNames));
            })
            ->exists();
    }

    private function finalizeApproval(Leave $leave): void
    {
        $leave->status = 'approved';
        $leave->save();

        $this->incrementBalance($leave);
    }

    private function incrementBalance(Leave $leave): void
    {
        if (!$leave->leave_type_id) {
            return;
        }

        $type = $leave->leaveType;
        if (!$type || $type->annual_entitlement_days === null) {
            return;
        }

        $days = Leave::businessDaysBetween($leave->start_date, $leave->end_date);
        $year = $leave->start_date->year;

        $balance = LeaveBalance::firstOrCreate(
            [
                'employee_id' => $leave->employee_id,
                'leave_type_id' => $leave->leave_type_id,
                'year' => $year,
            ],
            [
                'organization_id' => $leave->organization_id,
                'entitled_days' => $type->annual_entitlement_days,
                'used_days' => 0,
            ]
        );

        $balance->increment('used_days', $days);
    }

    /**
     * Check whether an employee has enough remaining balance for a leave
     * type before it's submitted. Does not reserve/hold days — balance is
     * only actually consumed on final approval.
     */
    public function checkBalance(Employee $employee, LeaveType $leaveType, float $requestedDays, int $year): array
    {
        $balance = LeaveBalance::where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveType->id)
            ->where('year', $year)
            ->first();

        $entitled = $this->resolveEntitledDays($leaveType, $balance);

        if ($entitled === null) {
            return ['ok' => true, 'remaining' => null];
        }

        $used = $balance?->used_days ?? 0;

        $pending = Leave::where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveType->id)
            ->where('status', 'pending')
            ->whereYear('start_date', $year)
            ->get()
            ->sum(fn ($l) => Leave::businessDaysBetween($l->start_date, $l->end_date));

        $remaining = $entitled - (float) $used - $pending;

        return ['ok' => $remaining >= $requestedDays, 'remaining' => $remaining];
    }

    /**
     * A LeaveBalance row's own entitled_days (once one exists, whether
     * auto-snapshotted on first approval or manually set by an admin
     * override) always takes precedence over the leave type's default —
     * that row is the authoritative entitlement for that employee/year.
     * Falls back to the type's default when no row exists yet.
     */
    private function resolveEntitledDays(LeaveType $type, ?LeaveBalance $balance): ?float
    {
        if ($balance) {
            return (float) $balance->entitled_days;
        }

        return $type->annual_entitlement_days !== null ? (float) $type->annual_entitlement_days : null;
    }

    /**
     * Create or update an admin override for an employee's leave balance —
     * lets an admin manually set entitled/used days for a given
     * employee/leave type/year (e.g. pro-rating a new hire, correcting a
     * mistake, or granting a specific allowance on an otherwise untracked
     * leave type).
     */
    public function setBalanceOverride(int $organizationId, int $employeeId, int $leaveTypeId, int $year, float $entitledDays, float $usedDays): LeaveBalance
    {
        return LeaveBalance::updateOrCreate(
            ['employee_id' => $employeeId, 'leave_type_id' => $leaveTypeId, 'year' => $year],
            ['organization_id' => $organizationId, 'entitled_days' => $entitledDays, 'used_days' => $usedDays]
        );
    }

    /**
     * Balance summary (entitled / used / pending / remaining) for every
     * active leave type in the employee's organization, for the given year.
     * Used to let an applicant see their leave balance before applying.
     */
    public function balancesForEmployee(Employee $employee, int $year): array
    {
        $types = LeaveType::where('organization_id', $employee->organization_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return $types->map(function (LeaveType $type) use ($employee, $year) {
            $balance = LeaveBalance::where('employee_id', $employee->id)
                ->where('leave_type_id', $type->id)
                ->where('year', $year)
                ->first();

            $entitled = $this->resolveEntitledDays($type, $balance);

            if ($entitled === null) {
                return [
                    'leave_type_id' => $type->id,
                    'code' => $type->code,
                    'name' => $type->name,
                    'icon' => $type->icon,
                    'entitled_days' => null,
                    'used_days' => 0,
                    'pending_days' => 0,
                    'remaining_days' => null,
                ];
            }

            $used = (float) ($balance->used_days ?? 0);

            $pending = Leave::where('employee_id', $employee->id)
                ->where('leave_type_id', $type->id)
                ->where('status', 'pending')
                ->whereYear('start_date', $year)
                ->get()
                ->sum(fn ($l) => Leave::businessDaysBetween($l->start_date, $l->end_date));

            return [
                'leave_type_id' => $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'icon' => $type->icon,
                'entitled_days' => $entitled,
                'used_days' => $used,
                'pending_days' => $pending,
                'remaining_days' => $entitled - $used - $pending,
            ];
        })->values()->toArray();
    }

    /**
     * Balance summary for a single leave type across a list of employees
     * (entitled / used taken from leaves taken & approved / pending / remaining),
     * for an admin-facing report. Uses bulk queries to avoid N+1 per employee.
     */
    public function balancesForType(LeaveType $type, \Illuminate\Support\Collection $employees, int $year): array
    {
        $employeeIds = $employees->pluck('id');

        $balancesByEmployee = LeaveBalance::whereIn('employee_id', $employeeIds)
            ->where('leave_type_id', $type->id)
            ->where('year', $year)
            ->get()
            ->keyBy('employee_id');

        $pendingByEmployee = Leave::whereIn('employee_id', $employeeIds)
            ->where('leave_type_id', $type->id)
            ->where('status', 'pending')
            ->whereYear('start_date', $year)
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($group) => $group->sum(fn ($l) => Leave::businessDaysBetween($l->start_date, $l->end_date)));

        return $employees->map(function (Employee $employee) use ($type, $balancesByEmployee, $pendingByEmployee, $year) {
            $balance = $balancesByEmployee->get($employee->id);
            $entitled = $this->resolveEntitledDays($type, $balance);
            $used = (float) ($balance?->used_days ?? 0);
            $pending = (float) ($pendingByEmployee->get($employee->id) ?? 0);

            return [
                'employee_id' => $employee->id,
                'employee_name' => $employee->name,
                'department' => $employee->department->name ?? null,
                'leave_type_id' => $type->id,
                'leave_type_name' => $type->name,
                'leave_type_code' => $type->code,
                'leave_type_icon' => $type->icon,
                'year' => $year,
                'entitled_days' => $entitled,
                'used_days' => $used,
                'pending_days' => $pending,
                'remaining_days' => $entitled !== null ? $entitled - $used - $pending : null,
                'has_override' => $balance !== null,
            ];
        })->values()->toArray();
    }

    /**
     * Balance rows for every given leave type across the given employees,
     * flattened and sorted by employee then leave type — lets an admin see
     * every leave type's balance for a set of employees (or a single
     * employee) in one list, rather than one type at a time.
     */
    public function balancesForTypes(\Illuminate\Support\Collection $types, \Illuminate\Support\Collection $employees, int $year): array
    {
        $rows = [];

        foreach ($types as $type) {
            array_push($rows, ...$this->balancesForType($type, $employees, $year));
        }

        usort($rows, fn ($a, $b) => [$a['employee_name'], $a['leave_type_name']] <=> [$b['employee_name'], $b['leave_type_name']]);

        return $rows;
    }

    private function sendNotifications(Leave $leave, array $config, int $level): void
    {
        $recipients = [];

        if ($config['approver_type'] === 'user' && $config['approver_user_id']) {
            $user = User::find($config['approver_user_id']);
            if ($user?->email) {
                $recipients[] = $user->email;
            }
        } elseif ($config['approver_type'] === 'role' && $config['approver_role']) {
            $recipients = User::role($config['approver_role'])
                ->whereHas('employee', fn ($q) => $q->where('organization_id', $leave->organization_id))
                ->pluck('email')
                ->filter()
                ->all();
        }

        if (!empty($config['notify_email'])) {
            $recipients = array_merge($recipients, array_filter($config['notify_email_addresses'] ?? []));
        }

        $recipients = array_values(array_unique($recipients));

        if (!empty($recipients)) {
            $approverRoleLabel = $config['approver_type'] === 'role' ? ($config['approver_role'] ?? null) : null;

            Notification::route('mail', $recipients)
                ->notify(new LeaveApprovalRequiredNotification($leave, $level, $approverRoleLabel));
        }
    }
}
