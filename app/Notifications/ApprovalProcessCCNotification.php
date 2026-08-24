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

class ApprovalProcessCCNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Leave $leave,
    ) {
        $this->afterCommit();
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
            ->where('status', 'rejected')
            ->latest('closed_at')
            ->first();

        if (!$activeLog) {
            Log::error('No active reject log found WHEN CC-ING for leave ID: ' . $this->leave->id);

            return (new MailMessage)
                ->subject('Update on Employee Leave Request')
                ->line('A leave request update is available, but detailed review metadata could not be loaded.');
        }

        return (new MailMessage)
            ->subject('Update on Employee Leave Request')
            ->view('emails.leaves.leave-update-cc', [
                'employeeName' => $employeeName,
                'leaveTypeName' => $leaveType->name ?? $this->leave->leave_type,
                'startDate' => $this->leave->start_date->format('d M Y'),
                'endDate' => $this->leave->end_date->format('d M Y'),
                'totalDays' => $totalDays,
                'reviewerName' => User::find($activeLog->actioned_by)?->name ?? '',
                'approvalStatus' => ucfirst($activeLog->status),
                'reviewDate' => $activeLog->closed_at?->format('d M Y'),
                'rejectionReason' => $activeLog->notes ?? '',
                'orgName' => $employee->organization->name ?? config('app.name'),
            ]);
    }
}
