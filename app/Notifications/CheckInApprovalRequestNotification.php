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
            ->greeting('New Check-in Approval Request')
            ->line(($employee->name ?? 'An employee') . ' checked in ' . $this->request->minutes_late . ' minutes late on ' . $this->request->date->format('d M Y') . '.')
            ->line('This request requires action from: ' . $this->approverRole)
            ->action('Review Request', $reviewUrl)
            ->line('If no action is taken before the timeout, this request will be escalated or auto-resolved according to your organization\'s approval policy.');
    }
}
