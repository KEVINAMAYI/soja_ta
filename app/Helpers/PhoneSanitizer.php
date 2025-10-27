<?php

namespace App\Helpers;

use Illuminate\Support\Str;

class PhoneSanitizer
{
    /**
     * Sanitize a phone number based on the current app domain.
     *
     * @param string $phone
     * @return string
     */
    public static function sanitize(string $phone): string
    {
        $host = request()->getHost();

        // Remove any non-numeric characters first
        $phone = preg_replace('/\D/', '', $phone);

        // Uganda
        if (Str::contains($host, '.ug')) {
            if (Str::startsWith($phone, '0')) {
                $phone = substr($phone, 1);
            }
            if (!Str::startsWith($phone, '256')) {
                $phone = '256' . $phone;
            }
            return '+' . $phone;
        }

        // Default/fallback: Kenya
        if (Str::startsWith($phone, '0')) {
            $phone = substr($phone, 1);
        }
        if (!Str::startsWith($phone, '254')) {
            $phone = '254' . $phone;
        }

        return '+' . $phone;
    }
}

