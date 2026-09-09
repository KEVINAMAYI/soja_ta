<?php

namespace App\Jobs;

use App\Mail\SuperAdminPasswordResetMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendSuperAdminPasswordResetEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $email,
        public string $resetUrl,
        public int $expiresInHours,
    ) {}

    public function handle(): void
    {
        try {
            Mail::to($this->email)->send(
                new SuperAdminPasswordResetMail($this->name, $this->resetUrl, $this->expiresInHours)
            );
        } catch (\Throwable $e) {
            Log::error('SendSuperAdminPasswordResetEmailJob failed', [
                'email' => $this->email,
                'error' => $e->getMessage(),
                'line'  => $e->getLine(),
                'file'  => $e->getFile(),
            ]);

            throw $e;
        }
    }
}
