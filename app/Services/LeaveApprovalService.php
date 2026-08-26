<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveAlternativeDate;
use App\Models\LeaveApprovalLog;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\LevelApprover;
use App\Models\User;
use App\Notifications\LeaveApprovalRequiredNotification;
use App\Notifications\LeaveApprovedNotification;
use App\Notifications\LeaveRejectedNotification;
use App\Notifications\ApprovalProcessCCNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        // TODO(SIR-DOMMY): This value will be controlled by superadmin and will require new field db... for now we disable this check
        // if (!$settings['enabled']) {
        //     return null;
        // }

        $start = LeaveApprovalSettings::firstEnabledLevel($settings);
        if ($start === null) {
            // reject the leave if there are no enabled levels in the approval chain
            $this->reject($leave, null, 'Leave approval chain has no enabled levels');
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

        $latest_actor = $leave->latestApprovedApprovalLog()->first()?->actioned_by;

        $next_approver_title_id = $leave->employee?->reports_to_job_title_id;
        if ($latest_actor && $config['approver_type'] == 'role' && $level > 1) {
            $next_approver_title_id = Employee::where('user_id', $latest_actor)->value('reports_to_job_title_id');

            // if next approver is null, it means the applicant has no reporting level above them for next level approval, so we auto approve the leave and return
            if (!$next_approver_title_id) {
                Log::warning('Leave approval auto-approved: applicant has no reporting level above them for next level approval', [
                    'leave_id' => $leave->id,
                    'employee_id' => $leave->employee_id,
                ]);

                $this->approve($leave, auth()->user(), "Leave approval auto-approved OPEN-LEVEL: applicant has no reporting level above them for next level approval");
                return $leave->latestApprovedApprovalLog()->first();
            }
            
        }

        $log = LeaveApprovalLog::create([
            'leave_id' => $leave->id,
            'level_number' => $level,
            'approver_type' => $config['approver_type'],
            'approver_role' => $config['approver_type'] === 'role' ? $next_approver_title_id : null,
            'approver_user_ids' => $config['approver_type'] === 'user'
                ? array_values(array_unique(array_filter(array_map(
                    fn ($id) => is_numeric($id) ? (int) $id : null,
                    $config['approver_user_ids'] ?? []
                ))))
                : [],
            'approver_user_id' => $config['approver_type'] === 'user'
                ? ($config['approver_user_ids'][0] ?? $config['approver_user_id'] ?? null)
                : null,
            'status' => 'pending',
            'opened_at' => now(),
        ]);

        $leave->current_level = $level;
        $leave->save();
        $leave->refresh();

        $count_leave_logs = $leave->approvalLogs()->count();
        if ($count_leave_logs == 1 && $log->approver_type === 'role' && !$next_approver_title_id) {
            Log::warning('Leave approval notification skipped: applicant has no reports_to_job_title_id', [
                'leave_id' => $leave->id,
                'employee_id' => $leave->employee_id,
            ]);

            $this->reject($leave, null, "Leave approval failed: applicant has no reporting level above them for first level approval");
            return $log;
        }

        // if the next approver is the same as the previous approver, skip to the next level
        if ($next_approver_title_id === $leave->latestApprovedApprovalLog()->first()?->approver_role) {
            return $this->advanceOrFinalize($leave, $level, $settings);
        }

        $this->sendNotifications($leave, $config, $level, $next_approver_title_id);

        return $log;
    }

    private function advanceOrFinalize(Leave $leave, int $fromLevel, array $settings, ?string $notes = null): ?LeaveApprovalLog
    {
        $next = LeaveApprovalSettings::nextEnabledLevel($settings, $fromLevel, $leave);

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

        $alternative = LeaveAlternativeDate::where('leave_id', $leave->id)
            ->where('status', 'pending')
            ->latest()->first();

        if ($alternative) {
            throw new \RuntimeException("There's a pending leave dates changes");
        }

        $activeLog = $leave->activeApprovalLog()->first();

        if (!$activeLog) {
            throw new \RuntimeException('No pending approval level found for this leave.');
        }

        $this->authorizeActor($actor, $activeLog);


        $settings = LeaveApprovalSettings::get($leave->organization_id, $leave->department_id);


        // now save these details to list of approvers if rule applies
        if ($activeLog->approver_type === 'user' && $settings['levels'][$activeLog->level_number - 1]['approver_rule'] == 'all_approve') {
            LevelApprover::firstOrCreate([
                'leave_approval_log_id' => $activeLog->id,
                'level_approver_id' => $actor->id,
                'action' => 'approved',
            ]);

            if(LevelApprover::getActionedApproversCountForLog($activeLog->id) < count($activeLog->approver_user_ids)) {
                return $leave->fresh();
            }
        }

        $activeLog->update([
            'status' => 'approved',
            'closed_at' => now(),
            'actioned_by' => $actor->id,
            'notes' => $notes,
        ]);

        $this->advanceOrFinalize($leave, $activeLog->level_number, $settings, $notes);

        // TODO(SIR-DOMMY): Send mails to those who are to be CCed at this level
        $this->sendCCNotification($leave);

        return $leave->fresh();
    }

    /**
     * Reject the currently active level. A rejection at any level
     * immediately finalizes the leave as rejected — no further levels open.
     */
    public function reject(Leave $leave, ?User $actor, ?string $notes = null): Leave
    {
        // TO DO(SIR-DOMMY): Will an active leave date change request prevent a leave from being rejected? For now, we allow rejection even if there's a pending alternative date request.

        $activeLog = $leave->activeApprovalLog()->first();

        if (!$activeLog) {
            throw new \RuntimeException('No pending approval level found for this leave.');
        }

        try {
            DB::beginTransaction();

            if ($actor) {
                $this->authorizeActor($actor, $activeLog);
            }

            $activeLog->update([
                'status' => 'rejected',
                'closed_at' => now(),
                'actioned_by' => $actor?->id,
                'notes' => $notes,
            ]);

            $leave->status = 'rejected';
            $leave->save();

            // now save these details to list of approvers if rule applies
            if ($activeLog->approver_type === 'user' && $actor) {
                $existingApprover = LevelApprover::where('leave_approval_log_id', $activeLog->id)
                    ->where('level_approver_id', $actor?->id)
                    ->first();
                
                if(!$existingApprover) {
                    LevelApprover::create([
                        'leave_approval_log_id' => $activeLog->id,
                        'level_approver_id' => $actor?->id,
                        'action' => 'rejected',
                    ]);
                } else {
                    $existingApprover->update([
                        'action' => 'rejected',
                    ]);
                }
            }

            // commit db changes
            DB::commit();

            // Send notifications only after commit so queued mail jobs do not
            // race against uncommitted approval-log updates.
            $this->sendRejectionNotification($leave);

            return $leave->fresh();

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error while rejecting leave approval', [
                'leave_id' => $leave->id,
                'actor_id' => $actor?->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Handle an applicant's response to a leave alternative date request.
     * If the applicant rejects the alternative dates, the leave is rejected.
     */
    public function actionOnLeaveDatesChange(string $action, int $leaveId, LeaveAlternativeDate $alternativeDates)
    {
        $leave = Leave::find($leaveId);
        if (!$leave) {
            return ['Leave request not found.', null];
        }

        // save action to the alternative date record for auditing purposes
        $alternativeDates->update(['status' => $action]);

        // save the new leave dates to the leave record if the alternative dates are approved or rejected
        $leave->update([
            'start_date' => $alternativeDates->new_start_date,
            'end_date' => $alternativeDates->new_end_date,
            'num_of_days' => $alternativeDates->new_num_of_days,
        ]);

        if ($action === 'reject') {
            // reject the leave request if the alternative dates are rejected
            $this->reject($leave, null, 'Leave alternative dates rejected by applicant');
        }

    }

    private function authorizeActor(User $actor, LeaveApprovalLog $log): void
    {
        if (!$this->matchesApprover($actor, $log)) {
            throw new \RuntimeException('You are not authorized to action this approval level.');
        }
    }

    private function matchesApprover(User $actor, LeaveApprovalLog $log): bool
    {
        
        if ($log->approver_type == 'role') {
            $employee = Employee::where('user_id', $actor->id)->first();
            
            return $employee?->job_title_id == $log->approver_role;
        }

        // retain this for backward compatibility with legacy single approver ID, but prefer the new array of IDs if present
        else if ($log->approver_type !== 'user') {
            $employee = Employee::where('user_id', $actor->id)->first();
            return $employee?->job_title_id === $log->approver_role;
        }

        $approverIds = array_filter(array_unique(array_merge(
            $log->approver_user_ids ?? [],
            $log->approver_user_id ? [$log->approver_user_id] : []
        )));

        return in_array($actor->id, $approverIds, true);
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

        $matchesDirectly = LeaveApprovalLog::where('status', 'pending')
            ->whereHas('leave', fn ($q) => $q->where('organization_id', $organizationId))
            ->where(function ($q) use ($actor, $roleNames) {
                $q->where(function ($q2) use ($actor) {
                        $q2->where('approver_type', 'user')
                            ->where('approver_user_id', $actor->id);
                    })
                    ->orWhere(function ($q2) use ($roleNames) {
                        $q2->where('approver_type', 'role')
                            ->whereIn('approver_role', $roleNames);
                    });
            })
            ->exists();

        if ($matchesDirectly) {
            return true;
        }

        // approver_user_ids is stored as JSON-encoded text, so membership can't be
        // matched in SQL — filter the (small, already scoped) candidate set in PHP.
        return LeaveApprovalLog::where('status', 'pending')
            ->where('approver_type', 'user')
            ->whereHas('leave', fn ($q) => $q->where('organization_id', $organizationId))
            ->get()
            ->contains(fn ($log) => in_array($actor->id, $log->approver_user_ids ?? [], true));
    }

    private function finalizeApproval(Leave $leave): void
    {
        $leave->status = 'approved';
        $leave->save();

        $this->incrementBalance($leave);

        $this->sendApprovedNotification($leave);
    }

    private function sendApprovedNotification(Leave $leave): void
    {
        $leave->loadMissing(['employee.organization', 'leaveType']);

        $employee = $leave->employee;
        if (!$employee) {
            return;
        }

        $email = $employee->email ?? $employee->user?->email;
        if (empty($email)) {
            Log::warning('No email found for leave applicant', [
                'leave_id' => $leave->id,
                'employee_id' => $employee->id,
            ]);

            return;
        }

        Notification::route('mail', $email)
            ->notify(new LeaveApprovedNotification($leave));
    }

    private function sendRejectionNotification(Leave $leave): void
    {
        $leave->loadMissing(['employee.organization', 'leaveType']);

        $employee = $leave->employee;
        if (!$employee) {
            return;
        }

        $email = $employee->email ?? $employee->user?->email;
        if (empty($email)) {
            Log::warning('No email found for leave applicant while rejecting', [
                'leave_id' => $leave->id,
                'employee_id' => $employee->id,
            ]);

            return;
        }

        Notification::route('mail', $email)
            ->notify(new LeaveRejectedNotification($leave));

        // Send CC notification to the relevant parties
        $this->sendCCNotification($leave);
    }

    private function sendCCNotification(Leave $leave): void
    {
        $activeLog = $leave->latestApprovalLog()->first();

        $settings = LeaveApprovalSettings::get($leave->organization_id, $leave->department_id);

        if (!$activeLog) {
            Log::error('No active approval log found for leave ID: ' . $leave->id);
            return;
        }

        $conf = $settings['levels'][$activeLog->level_number - 1] ?? null;

        if (!$conf || !$conf['enabled']) {
            Log::error('No enabled configuration found for active approval log of leave ID: ' . $leave->id);
            return;
        }

        $emails = $conf['notify_email_addresses'] ?? [];

        foreach ($emails as $email) {
            if (empty($email)) {
                Log::warning('Empty email found in CC list for leave notification', [
                    'leave_id' => $leave->id,
                ]);
                continue;
            }

            Notification::route('mail', $email)
                ->notify(new ApprovalProcessCCNotification($leave));
        }
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

        $days = $leave->num_of_days;
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
            ->sum('num_of_days');

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
                ->sum(fn ($l) => $l->start_date->diffInDays($l->end_date) + 1);

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
            ->map(fn ($group) => (float) $group->sum('num_of_days'));

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

    private function sendNotifications(Leave $leave, array $config, int $level, ?int $next_approver_title_id): void
    {

        $recipients = [];

        if ($config['approver_type'] === 'user') {
            $approverIds = array_values(array_unique(array_filter(array_map(
                fn ($id) => is_numeric($id) ? (int) $id : null,
                array_merge(
                    $config['approver_user_ids'] ?? [],
                    // this check is added to allow legacy single approver ID to be used in the config,
                    //but it will be ignored if approver_user_ids is present and non-empty
                    (isset($config['approver_user_id']) && count($config['approver_user_ids']) > 0) ? [$config['approver_user_id']] : []
                )
            ))));

            if (!empty($approverIds)) {
                $recipients = User::whereIn('id', $approverIds)
                    ->whereNotNull('email')
                    ->pluck('email')
                    ->filter()
                    ->all();
            }
        }
        else if ($config['approver_type'] === 'role') {


            if (!$next_approver_title_id) {
                Log::warning('Leave approval notification skipped: applicant has no reports_to_job_title_id', [
                    'leave_id' => $leave->id,
                    'employee_id' => $leave->employee_id,
                ]);

            }
                        
            if ($next_approver_title_id) {

                $recipients = Employee::where('organization_id', $leave->organization_id)
                    ->where('job_title_id', $next_approver_title_id)
                    ->pluck('email')
                    ->filter()
                    ->all();
            }
        // avoid using role but report to job title if approver type is role
        // elseif ($config['approver_type'] === 'role' && $config['approver_role']) {
        //     $recipients = User::role($config['approver_role'])
        //         ->whereHas('employee', fn ($q) => $q->where('organization_id', $leave->organization_id))
        //         ->pluck('email')
        //         ->filter()
        //         ->all();

        }

        // if (!empty($config['notify_email'])) {
        //     $recipients = array_merge($recipients, array_filter($config['notify_email_addresses'] ?? []));
        // }

        $recipients = array_values(array_unique($recipients));

        if (!empty($recipients)) {
            $approverRoleLabel = $config['approver_type'] === 'role' ? ($config['approver_role'] ?? null) : null;

            // use for each since I want to send customized email to each approver with their email in the review link --> SIR-DOMMY
            foreach ($recipients as $recipientEmail) {
                Notification::route('mail', $recipientEmail)
                    ->notify(new LeaveApprovalRequiredNotification($leave, $level, $approverRoleLabel));
            }
        }
    }
    
}
