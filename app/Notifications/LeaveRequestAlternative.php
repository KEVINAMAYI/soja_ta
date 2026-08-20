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

class LeaveRequestAlternative extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public array $leave,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {


        return (new MailMessage)
            ->subject('Leave Request Not Approved - Alternative Dates Proposed')
            ->view('emails.leaves.leave-alternative-date-proposed', [
                'employeeName' => $this->leave['employeeName'],
                'leaveTypeName' => $this->leave['leaveTypeName'],
                'originalStartDate' => $this->leave['originalStartDate'],
                'originalEndDate' => $this->leave['originalEndDate'],

                // TODO: Fetch new start and end dates from the leave request's alternative from new table
                'newStartDate' => $this->leave['newStartDate'],
                'newEndDate' => $this->leave['newEndDate'],
                'newNumberOfDays' => $this->leave['newNumberOfDays'],
                'companyName' => $this->leave['companyName'],

                // TODO: Generate actual accept and reject URLs for the leave request
                'acceptUrl' => $this->leave['acceptUrl'] ?? 'google.com', // Placeholder for accept URL
                'rejectUrl' => $this->leave['rejectUrl'] ?? 'google.com',
            ]);
    }
}
