<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // For every employee that has a shift_id set,
        // ensure there's a pivot row and mark it as primary
        $employees = DB::table('employees')
            ->whereNotNull('shift_id')
            ->select('id', 'shift_id')
            ->get();

        foreach ($employees as $employee) {
            // Insert pivot row if it doesn't exist
            DB::table('employee_shifts')->insertOrIgnore([
                'employee_id' => $employee->id,
                'shift_id'    => $employee->shift_id,
                'is_primary'  => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            // If a row already existed (insertOrIgnore skipped),
            // make sure it's marked as primary
            DB::table('employee_shifts')
                ->where('employee_id', $employee->id)
                ->where('shift_id', $employee->shift_id)
                ->update(['is_primary' => true]);

            // Ensure no other shifts for this employee are marked primary
            DB::table('employee_shifts')
                ->where('employee_id', $employee->id)
                ->where('shift_id', '!=', $employee->shift_id)
                ->update(['is_primary' => false]);
        }
    }

    public function down(): void
    {
        // Revert: remove pivot rows that were created from shift_id
        // (only removes rows where is_primary = true and matches employees.shift_id)
        $employees = DB::table('employees')
            ->whereNotNull('shift_id')
            ->select('id', 'shift_id')
            ->get();

        foreach ($employees as $employee) {
            DB::table('employee_shifts')
                ->where('employee_id', $employee->id)
                ->where('shift_id', $employee->shift_id)
                ->where('is_primary', true)
                ->delete();
        }
    }
};
