<?php

namespace App\Services;

use App\Models\Otp;
use App\Helpers\PhoneSanitizer;

class OtpService
{
    public static function validateOtp(string $phone, string $otp)
    {
        $phone = PhoneSanitizer::sanitize($phone);

        $otpRecord = Otp::where('phone', $phone)->latest()->first();

        if (!$otpRecord || $otpRecord->otp !== $otp || $otpRecord->isExpired()) {
            return [
                'valid' => false,
                'message' => 'Invalid or expired OTP',
            ];
        }

        // If valid, delete OTP (optional depending on use case)
        $otpRecord->delete();

        return [
            'valid' => true,
            'message' => 'OTP validated successfully',
        ];
    }
}
