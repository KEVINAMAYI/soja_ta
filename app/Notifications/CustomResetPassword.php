<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class CustomResetPassword extends Notification
{
    public $url;
    public $companyName;

    public function __construct($url, $companyName)
    {
        $this->url = $url;
        $this->companyName = $companyName;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }


    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject("Complete your SOJA TA account setup")
            ->view('emails.custom_reset_password', [
                'url' => $this->url,
                'companyName' => $this->companyName,
                'user' => $notifiable,
            ]);
    }
}
