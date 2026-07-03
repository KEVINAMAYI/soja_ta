<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class CustomResetPassword extends Notification
{
    public $url;
    public $companyName;
    public $brandColor;

    public function __construct($url, $companyName, $brandColor = null)
    {
        $this->url = $url;
        $this->companyName = $companyName;
        $this->brandColor = $brandColor ?: '#072639';
    }

    public function via($notifiable)
    {
        return ['mail'];
    }


    public function toMail($notifiable)
    {
        $name = $notifiable->name ?? 'there';

        return (new MailMessage)
            ->subject("Complete your SOJA TA account setup")
            ->view('emails.custom_reset_password', [
                'url' => $this->url,
                'companyName' => $this->companyName,
                'brandColor' => $this->brandColor,
                'user' => $notifiable,
                'userInitials' => $this->initials($name),
            ]);
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));
        $initials = strtoupper(($parts[0][0] ?? '') . ($parts[1][0] ?? ''));

        return $initials ?: '?';
    }
}
