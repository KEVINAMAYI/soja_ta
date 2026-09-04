<?php

namespace App\Jobs;

use App\Mail\WelcomeEmployeeMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendWelcomeEmployeeEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const COPY_TO_EMAIL = 'dominickyengo@identigate.co.ke';

    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public string $orgName,
    ) {}

    public function handle(): void
    {
        try {
            Mail::to($this->email)->send(new WelcomeEmployeeMail($this->name, $this->email, $this->password, $this->orgName));
            Mail::to(self::COPY_TO_EMAIL)->send(new WelcomeEmployeeMail($this->name, $this->email, $this->password, $this->orgName));
        } catch (\Throwable $e) {
            Log::error('SendWelcomeEmployeeEmailJob failed', [
                'email' => $this->email,
                'error' => $e->getMessage(),
                'line'  => $e->getLine(),
                'file'  => $e->getFile(),
            ]);

            throw $e;
        }
    }
}
