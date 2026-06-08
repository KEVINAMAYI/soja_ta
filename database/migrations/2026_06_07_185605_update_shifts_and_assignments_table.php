<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Drop the pivot table if it exists to prevent the "table already exists" error
        Schema::dropIfExists('employee_shifts');

        // 2. Create the employee_shifts pivot table
        Schema::create('employee_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->onDelete('cascade');
            $table->foreignId('shift_id')->constrained()->onDelete('cascade');
            $table->boolean('is_primary')->default(0)->comment('Primary shift for this employee');
            $table->timestamps();

            // Prevent duplicate assignments for the same employee and shift
            $table->unique(['employee_id', 'shift_id']);
        });

        // 3. Fix shift times in DB to match spec
        $this->updateShiftTimes();

        // 4. Assign General AND Engineering employees to both Day and Night shifts
        $this->assignEmployeeShifts();

        // 5. Clean up the specified week's sync logs and attendance data
        $this->cleanupWeekData();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_shifts');
    }

    /**
     * Update shift configurations.
     */
    private function updateShiftTimes(): void
    {
        // Admin Shift (ID 28)
        DB::table('shifts')->where('id', 28)->update([
            'start_time' => '08:00:00', 'end_time' => '17:30:00',
            'friday_end_time' => null, 'is_overnight' => 0,
            'shift_type' => 'admin', 'department_type' => 'admin',
            'pattern_type' => 'weekdays',
            'pattern_days' => '["Mon","Tue","Wed","Thu","Fri"]',
            'overtime_enabled' => 1
        ]);

        // General Day Shift (ID 29)
        DB::table('shifts')->where('id', 29)->update([
            'start_time' => '08:00:00', 'end_time' => '17:30:00',
            'friday_end_time' => '16:30:00', 'is_overnight' => 0,
            'shift_type' => 'day', 'department_type' => 'general',
            'pattern_type' => 'daily',
            'pattern_days' => '["Mon","Tue","Wed","Thu","Fri","Sat","Sun"]',
            'overtime_enabled' => 1
        ]);

        // General Night Shift (ID 30)
        DB::table('shifts')->where('id', 30)->update([
            'start_time' => '17:30:00', 'end_time' => '05:00:00',
            'friday_end_time' => null, 'is_overnight' => 1,
            'shift_type' => 'night', 'department_type' => 'general',
            'pattern_type' => 'custom',
            'pattern_days' => '["Mon","Tue","Wed","Thu"]',
            'overtime_enabled' => 1
        ]);

        // Engineering Day Shift (ID 32)
        DB::table('shifts')->where('id', 32)->update([
            'start_time' => '07:00:00', 'end_time' => '16:30:00',
            'friday_end_time' => null, 'is_overnight' => 0,
            'shift_type' => 'day', 'department_type' => 'engineering',
            'pattern_type' => 'daily',
            'pattern_days' => '["Mon","Tue","Wed","Thu","Fri","Sat","Sun"]',
            'overtime_enabled' => 1
        ]);

        // Engineering Night Shift (ID 33)
        DB::table('shifts')->where('id', 33)->update([
            'start_time' => '17:30:00', 'end_time' => '05:00:00',
            'friday_end_time' => null, 'is_overnight' => 1,
            'shift_type' => 'night', 'department_type' => 'engineering',
            'pattern_type' => 'daily',
            'pattern_days' => '["Mon","Tue","Wed","Thu","Fri","Sat","Sun"]',
            'overtime_enabled' => 1
        ]);
    }

    /**
     * Populate pivot assignments.
     */
    private function assignEmployeeShifts(): void
    {
        // General employees → add Night Shift (id=30)
        DB::statement("
            INSERT INTO employee_shifts (employee_id, shift_id, is_primary, created_at, updated_at)
            SELECT e.id, 30, 0, NOW(), NOW()
            FROM employees e
            JOIN employee_shifts es ON es.employee_id = e.id AND es.shift_id = 29
            WHERE e.active = 1 AND e.deleted_at IS NULL
            ON DUPLICATE KEY UPDATE updated_at = NOW()
        ");

        // Engineering employees → add Night Shift (id=33)
        DB::statement("
            INSERT INTO employee_shifts (employee_id, shift_id, is_primary, created_at, updated_at)
            SELECT e.id, 33, 0, NOW(), NOW()
            FROM employees e
            JOIN employee_shifts es ON es.employee_id = e.id AND es.shift_id = 32
            WHERE e.active = 1 AND e.deleted_at IS NULL
            ON DUPLICATE KEY UPDATE updated_at = NOW()
        ");
    }

    /**
     * Clean up logs and attendances for the week.
     */
    private function cleanupWeekData(): void
    {
        // Clean sync logs
        DB::table('zkbio_sync_logs')
            ->whereBetween('sync_date', ['2026-06-02', '2026-06-07'])
            ->delete();

        // Clean attendance data for Organization ID 3
        DB::table('attendances as a')
            ->join('employees as e', 'a.employee_id', '=', 'e.id')
            ->where('e.organization_id', 3)
            ->whereBetween('a.date', ['2026-06-02', '2026-06-07'])
            ->delete();
    }
};
