<?php

namespace App\Jobs;

use App\Mail\SuperAdminWelcomeMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendSuperAdminWelcomeEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $email,
        public string $password,
    ) {}

    public function handle(): void
    {
        try {
            Mail::to($this->email)->send(new SuperAdminWelcomeMail($this->name, $this->email, $this->password));
        } catch (\Throwable $e) {
            Log::error('SendSuperAdminWelcomeEmailJob failed', [
                'email' => $this->email,
                'error' => $e->getMessage(),
                'line'  => $e->getLine(),
                'file'  => $e->getFile(),
            ]);

            throw $e;
        }
    }
}
