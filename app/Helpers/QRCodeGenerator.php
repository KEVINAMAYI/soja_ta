<?php

namespace App\Helpers;

use Illuminate\Support\Str;
use App\Models\Employee;

class QRCodeGenerator
{
    public static function generateEmployeeCode($organizationId, $employeeId): string
    {
        do {
            // Generate a short 5-char alphanumeric code
            $code = Str::upper(Str::random(5));

        } while (Employee::where('qr_code', $code)->exists());

        return $code;
    }
}
