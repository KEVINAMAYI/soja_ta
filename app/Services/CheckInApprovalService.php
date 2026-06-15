<?php

namespace App\Services;

use App\Models\ApprovalWindowLog;
use App\Models\Attendance;
use App\Models\CheckInApprovalRequest;
use App\Models\Employee;
use App\Notifications\CheckInApprovalRequestNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

class CheckInApprovalService
{
    /**
     * Decide whether a late check-in for this employee should be HELD for
     * approval instead of being clocked in immediately.
     *
     * Returns the configured settings array if approval should be triggered,
     * or null if the check-in should proceed normally.
     */
    public function shouldRequireApproval(Employee $employee, int $minutesLate): ?array
    {
        $orgId = $employee->organization_id;
        $settings = CheckInApprovalSettings::get($orgId);

        if (!$settings['enabled']) {
            return null;
        }

        // Use the shift's own grace period — single source of truth
        $shift = $employee->shift;
        $shiftGraceEnabled = $shift?->grace_period['enabled'] ?? false;
        $shiftGraceMinutes = $shift?->grace_period['minutes'] ?? 0;

        if ($shiftGraceEnabled && $minutesLate <= $shiftGraceMinutes) {
            return null;
        }

        if (!CheckInApprovalSettings::appliesToDepartment($settings, $employee->department_id ?? null)) {
            return null;
        }

        return $settings;
    }

    /**
     * Create a HELD check-in request. NO attendance record is created or
     * modified at this point — the employee is simply not checked in yet.
     */
    public function createRequest(
        Employee $employee,
        Carbon $checkInTime,
        int $minutesLate,
        bool $isLateCheckin,
        bool $withinGracePeriod,
        array $shiftTimes, // ['expected_check_in_time' => ..., 'grace_period_end_time' => ..., 'expected_check_out_time' => ..., 'early_checkout_threshold_time' => ...]
        float $latitude,
        float $longitude,
        ?int $deviceId,
        int $workLocationId,
        array $settings
    ): CheckInApprovalRequest {

        $request = CheckInApprovalRequest::create([
            'organization_id' => $employee->organization_id,
            'employee_id' => $employee->id,
            'date' => $checkInTime->toDateString(),
            'check_in_time' => $checkInTime,
            'minutes_late' => $minutesLate,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'device_id' => $deviceId,
            'work_location_id' => $workLocationId,
            'is_late_checkin' => $isLateCheckin,
            'within_grace_period' => $withinGracePeriod,
            'expected_check_in_time' => $shiftTimes['expected_check_in_time'],
            'grace_period_end_time' => $shiftTimes['grace_period_end_time'],
            'expected_check_out_time' => $shiftTimes['expected_check_out_time'],
            'early_checkout_threshold_time' => $shiftTimes['early_checkout_threshold_time'],
            'status' => 'pending',
            'current_window' => 1,
            'submitted_at' => now(),
        ]);

        $this->openWindow($request, 1, $settings);

        return $request;
    }

    /**
     * Open a window log for the given window number (1-3) and send
     * notifications according to the configured channels.
     */
    public function openWindow(CheckInApprovalRequest $request, int $windowNumber, array $settings): ?ApprovalWindowLog
    {
        $windowConfig = $settings['windows'][$windowNumber - 1] ?? null;

        if (!$windowConfig || !$windowConfig['enabled']) {
            return $this->advanceOrResolve($request, $windowNumber, $settings);
        }

        $opened = now();
        $log = ApprovalWindowLog::create([
            'check_in_approval_request_id' => $request->id,
            'window_number' => $windowNumber,
            'approver_role' => $windowConfig['approver_role'],
            'timeout_minutes' => (int)$windowConfig['timeout_minutes'],
            'on_timeout_action' => $windowConfig['on_timeout'],
            'opened_at' => $opened,
            'expires_at' => $opened->copy()->addMinutes((int)$windowConfig['timeout_minutes']),
            'status' => 'pending',
        ]);

        $request->current_window = $windowNumber;
        $request->save();

        $this->sendNotifications($request, $windowConfig);

        return $log;
    }

    private function advanceOrResolve(CheckInApprovalRequest $request, int $windowNumber, array $settings): ?ApprovalWindowLog
    {
        if ($windowNumber < 3) {
            return $this->openWindow($request, $windowNumber + 1, $settings);
        }

        // No windows configured at all — reject by default to be safe.
        $this->resolve($request, 'rejected', null, 'Auto-rejected: no approval window configured.');

        return null;
    }

    /**
     * Approve or reject the held request.
     *
     * - approved → CREATES the attendance record now, exactly as a normal
     *   check-in would have, using the original scan timestamp/payload.
     *   The employee becomes "clocked_in" retroactively from that time.
     *
     * - rejected → NOTHING is created. The day remains unchecked_in/absent
     *   as if the scan never happened. The request itself is the only
     *   audit trail.
     */
    public function resolve(CheckInApprovalRequest $request, string $decision, ?int $resolvedByUserId, ?string $notes = null): CheckInApprovalRequest
    {
        $decision = $decision === 'approved' ? 'approved' : 'rejected';

        $activeLog = $request->windowLogs()
            ->where('window_number', $request->current_window)
            ->where('status', 'pending')
            ->first();

        if ($activeLog) {
            $activeLog->update([
                'status' => $decision,
                'closed_at' => now(),
                'actioned_by' => $resolvedByUserId,
            ]);
        }

        if ($decision === 'approved') {
            $attendance = $this->finalizeAttendance($request);
            $request->attendance_id = $attendance->id;
        }

        $request->status = $decision;
        $request->resolved_at = now();
        $request->resolved_by = $resolvedByUserId;
        $request->notes = $notes;
        $request->save();

        return $request;
    }

    /**
     * Create the attendance record on approval, mirroring exactly what
     * AttendanceController::processCheckIn would have done for a normal
     * (non-held) check-in — including possibly reusing an existing
     * 'absent'/'unchecked_in' stub row for the day.
     */
    private function finalizeAttendance(CheckInApprovalRequest $request): Attendance
    {
        $employee = $request->employee;
        $date = $request->date->toDateString();

        $attendance = Attendance::where('employee_id', $employee->id)
            ->where('date', $date)
            ->whereIn('status', ['absent', 'unchecked_in'])
            ->whereNull('check_in_time')
            ->first() ?? new Attendance(['employee_id' => $employee->id, 'date' => $date]);

        $attendance->status = 'clocked_in';
        $attendance->check_in_time = $request->check_in_time;
        $attendance->latitude = $request->latitude;
        $attendance->longitude = $request->longitude;
        $attendance->device_id = $request->device_id;
        $attendance->work_location_id = $request->work_location_id;
        $attendance->is_late_checkin = $request->is_late_checkin;
        $attendance->minutes_late = $request->minutes_late;
        $attendance->within_grace_period = $request->within_grace_period;
        $attendance->is_early_checkout = false;
        $attendance->minutes_early = 0;
        $attendance->is_late_checkout = false;
        $attendance->late_checkout_hours = 0;
        $attendance->total_break_minutes = 0;
        $attendance->paid_break_minutes = 0;
        $attendance->excess_break_minutes = 0;
        $attendance->break_count = 0;
        $attendance->is_break_checkout = false;
        $attendance->expected_check_in_time = $request->expected_check_in_time;
        $attendance->grace_period_end_time = $request->grace_period_end_time;
        $attendance->expected_check_out_time = $request->expected_check_out_time;
        $attendance->early_checkout_threshold_time = $request->early_checkout_threshold_time;
        $attendance->save();

        return $attendance;
    }

    /**
     * Process timeouts for all currently-pending windows. Intended to be
     * run periodically (e.g. every minute) by a scheduled job.
     */
    public function processExpiredWindows(): int
    {
        $processed = 0;

        $expiredLogs = ApprovalWindowLog::where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->with('request.employee')
            ->get();

        foreach ($expiredLogs as $log) {
            $request = $log->request;
            if (!$request || !$request->isPending()) {
                continue;
            }

            $orgId = $request->organization_id;
            $settings = CheckInApprovalSettings::get($orgId);

            $log->update(['status' => 'expired', 'closed_at' => now()]);

            switch ($log->on_timeout_action) {
                case 'approve':
                    $this->resolve($request, 'approved', null, "Auto-approved on window {$log->window_number} timeout.");
                    break;

                case 'reject':
                    $this->resolve($request, 'rejected', null, "Auto-rejected on window {$log->window_number} timeout.");
                    break;

                case 'escalate':
                default:
                    $nextWindow = $log->window_number + 1;
                    if ($nextWindow <= 3) {
                        $this->openWindow($request, $nextWindow, $settings);
                    } else {
                        $this->resolve($request, 'rejected', null, 'Auto-rejected: escalation exhausted all windows.');
                    }
                    break;
            }

            $processed++;
        }

        return $processed;
    }

    private function sendNotifications(CheckInApprovalRequest $request, array $windowConfig): void
    {
        $emails = [];
        if (!empty($windowConfig['notify_email'])) {
            $emails = array_filter($windowConfig['notify_email_addresses'] ?? []);
        }

        $smsNumbers = [];
        if (!empty($windowConfig['notify_sms'])) {
            $smsNumbers = array_filter($windowConfig['notify_sms_numbers'] ?? []);
        }

        if (empty($emails) && empty($smsNumbers)) {
            return;
        }

        Notification::route('mail', $emails)
            ->notify(new CheckInApprovalRequestNotification($request, $windowConfig['approver_role'], $smsNumbers));
    }
}
