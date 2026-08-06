<?php

namespace App\Notifications;

use App\Models\Leave;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveApprovalRequiredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Leave $leave,
        public int $level,
        public ?string $approverRoleLabel = null,
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
        $employeeName = $employee->name ?? 'An employee';
        $totalDays = Leave::businessDaysBetween($this->leave->start_date, $this->leave->end_date);

        return (new MailMessage)
            ->subject('Leave Approval Required (Level ' . $this->level . '): ' . ($employee->name ?? 'Employee'))
            ->view('emails.leaves.approval-required', [
                'employeeName' => $employeeName,
                'employeeInitials' => $this->initials($employeeName),
                'employeeAvatarColor' => $this->avatarColor($employeeName),
                'leaveTypeName' => $leaveType->name ?? $this->leave->leave_type,
                'leaveTypeIcon' => $leaveType->icon ?? '📄',
                'startDate' => $this->leave->start_date->format('d M Y'),
                'endDate' => $this->leave->end_date->format('d M Y'),
                'totalDays' => $totalDays,
                'reason' => $this->leave->reason,
                'level' => $this->level,
                'totalLevels' => $this->leave->total_levels,
                'approverRoleLabel' => $this->approverRoleLabel,
                'reviewUrl' => route('leaves.index'),
                'orgName' => $employee->organization->name ?? config('app.name'),
                'brandColor' => $employee->organization->primary_color ?? '#072639',
            ]);
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));
        $initials = strtoupper(($parts[0][0] ?? '') . ($parts[1][0] ?? ''));

        return $initials ?: '?';
    }

    private function avatarColor(string $name): string
    {
        $palette = ['#6366f1', '#0ea5e9', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#14b8a6'];

        return $palette[crc32($name) % count($palette)];
    }
}
