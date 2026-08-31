<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

class QrTokenService
{
    /**
     * Employees that have a QR token assigned, for a given client organization.
     */
    public function qrTokensQuery(): Builder
    {
        return Employee::query()
            ->with('organization')
            ->whereNotNull('qr_code');
    }

    public function revokeToken(Employee $employee): Employee
    {
        $employee->update(['qr_code_revoked_at' => now()]);

        return $employee->fresh('organization');
    }

    public function activateToken(Employee $employee): Employee
    {
        $employee->update(['qr_code_revoked_at' => null]);

        return $employee->fresh('organization');
    }
}
