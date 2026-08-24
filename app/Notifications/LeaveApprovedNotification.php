<?php

namespace App\Notifications;

use App\Models\Leave;
use App\Models\LeaveApprovalLog;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class LeaveApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Leave $leave,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $employee = $this->leave->employee;
        $leaveType = $this->leave->leaveType;
        $employeeName = $employee->name ?? 'Employee';
        $totalDays = $this->leave->num_of_days;

        
        $activeLog = LeaveApprovalLog::where('leave_id', $this->leave->id)
            ->where('status', 'approved')
            ->latest('closed_at')
            ->first();
            
        if (!$activeLog) {
            Log::error('No active approval log found for leave ID: ' . $this->leave->id);
            return (new MailMessage)
                ->subject('Your Leave Request Has Been Approved ✅')
                ->line('Your leave request has been approved.');
        }


        // commented fields are not removed coz they might be used in the future. They are just not needed for now.
        return (new MailMessage)
            ->subject('Your Leave Request Has Been Approved ✅')
            ->view('emails.leaves.leave-approved', [
                'employeeName' => $employeeName,
                'leaveTypeName' => $leaveType->name ?? $this->leave->leave_type,
                //'leaveTypeIcon' => $leaveType->icon ?? '📄',
                'startDate' => $this->leave->start_date->format('d M Y'),
                'endDate' => $this->leave->end_date->format('d M Y'),
                'totalDays' => $totalDays,
                //'totalLevels' => $this->leave->total_levels,
                'approverName' => User::find($activeLog->actioned_by)?->name ?? '!APPROVED!',
                'approvalDate' => $activeLog->closed_at?->format('d M Y'),
                'approverComment' => $activeLog->notes ?? '',
                //'reason' => $this->leave->reason,
                //'resumption' => $this->leave->expected_resumption?->format('d M Y'),
                //'handoverName' => $this->leave->handover_to,
                'orgName' => $employee->organization->name ?? config('app.name'),
                //'brandColor' => $employee->organization->primary_color ?? '#072639',
            ]);
    }
}
