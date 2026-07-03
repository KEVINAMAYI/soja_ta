<?php

namespace App\Notifications;

use App\Models\CheckInApprovalRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

class CheckInApprovalRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public CheckInApprovalRequest $request,
        public string $approverRole,
        public array $smsNumbers = [],
    ) {
    }

    public function via(object $notifiable): array
    {
        // SMS dispatch is expected to be handled by an existing SMS service/channel
        // (e.g. App\Notifications\Channels\SmsChannel). Only 'mail' is wired here
        // since no SMS provider config was supplied.
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $employee = $this->request->employee;
        $reviewUrl = config('app.url') . '/admin/checkin-requests';

        return (new MailMessage)
            ->subject('⏰ Check-in Approval Required: ' . ($employee->name ?? 'Employee') . ' — ' . $this->request->minutes_late . ' min late')
            ->view('emails.checkin.approval-required', [
                'employeeName' => $employee->name ?? 'An employee',
                'date' => $this->request->date->format('d M Y'),
                'minutesLate' => $this->request->minutes_late,
                'approverRole' => $this->approverRole,
                'reviewUrl' => $reviewUrl,
                'orgName' => $employee->organization->name ?? config('app.name'),
                'brandColor' => $employee->organization->primary_color ?? '#072639',
            ]);
    }
}
