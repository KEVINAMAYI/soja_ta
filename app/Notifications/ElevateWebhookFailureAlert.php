<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ElevateWebhookFailureAlert extends Notification
{
    public function __construct(
        public string $organizationId,
        public string $date,
        public int $received,
        public int $failed,
        public array $reasons,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('ElevateHR leave webhook failure alert')
            ->line("Organisation {$this->organizationId}: {$this->failed} failed of {$this->received} received leave webhooks on {$this->date}.")
            ->line('Five most recent failure causes:');

        foreach ($this->reasons as $reason) {
            $mail->line($reason);
        }

        return $mail->line('Review the ElevateHR Webhook page under Account Settings for details.');
    }
}
