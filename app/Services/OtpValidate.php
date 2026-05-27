<?php

namespace App\Services;

use App\Models\Otp;
use App\Helpers\PhoneSanitizer;

class OtpValidate
{
    /**
     * Validate OTP for phone or email.
     *
     * @param string $type 'phone' or 'email'
     * @param string $value Phone number or email
     * @param string $otp
     * @return array
     */
    public static function validateOtp(string $type, string $value, string $otp): array
    {
        if ($type === 'phone') {
            $value = PhoneSanitizer::sanitize($value);
            $otpRecord = Otp::where('phone', $value)->latest()->first();
        } elseif ($type === 'email') {
            $otpRecord = Otp::where('email', $value)->latest()->first();
        } else {
            return [
                'valid' => false,
                'message' => 'Invalid type for OTP validation',
            ];
        }

        if (!$otpRecord || $otpRecord->otp !== $otp || $otpRecord->isExpired()) {
            return [
                'valid' => false,
                'message' => 'Invalid or expired OTP',
            ];
        }

        // Optionally delete OTP after successful validation
        $otpRecord->delete();

        return [
            'valid' => true,
            'message' => 'OTP validated successfully',
        ];
    }
}
