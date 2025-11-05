<?php

namespace App\Services;

use App\Models\Otp;
use Illuminate\Support\Facades\Mail;
use App\Helpers\PhoneSanitizer;

class OtpSender
{
    protected $smsService;

    public function __construct($smsService = null)
    {
        $this->smsService = $smsService;
    }

    /**
     * Send OTP to email or phone.
     *
     * @param string $type 'phone' or 'email'
     * @param string $value Phone number or email
     * @return bool
     */
    public function sendOtp(string $type, string $value): bool
    {
        $otpCode = rand(100000, 999999);

        // Store in DB with 5 min expiry
        $data = ['otp' => $otpCode, 'expires_at' => now()->addMinutes(5)];

        if ($type === 'phone') {

            $value = PhoneSanitizer::sanitize($value);
            $data['phone'] = $value;
            Otp::updateOrCreate(['phone' => $value], $data);

            if (!$this->smsService) {
                throw new \Exception('SMS service not injected.');
            }

            $this->smsService->sendSms($value, "Your OTP code is: {$otpCode}");
        } elseif ($type === 'email') {
            $data['email'] = $value;
            Otp::updateOrCreate(['email' => $value], $data);

            Mail::raw("Your OTP code is: {$otpCode}", function ($message) use ($value) {
                $message->to($value)->subject('Your OTP Code');
            });
        } else {
            throw new \Exception('Invalid OTP type');
        }

        return true;
    }
}
