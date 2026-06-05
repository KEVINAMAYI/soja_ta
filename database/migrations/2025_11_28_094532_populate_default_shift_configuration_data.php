<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Get all existing shifts
        $shifts = DB::table('shifts')->get();

        foreach ($shifts as $shift) {
            // Calculate duration from start_time and end_time
            $start = Carbon::parse($shift->start_time);
            $end = Carbon::parse($shift->end_time);

            // Handle overnight shifts (e.g., 22:00 to 06:00)
            if ($end->lt($start)) {
                $end->addDay();
            }

            $duration = $end->diffInHours($start, true); // true for precise calculation

            // Update shift with calculated values
            DB::table('shifts')
                ->where('id', $shift->id)
                ->update([
                    'duration_hours' => $duration,
                    'pattern_type' => 'weekdays',
                    'pattern_days' => json_encode(['Mon', 'Tue', 'Wed', 'Thu', 'Fri']),
                    'overtime_enabled' => true,
                    'max_overtime_hours' => 2.00,
                    'auto_clock_out' => false,
                    'warning_time_minutes' => 30,
                    'notify_managers_overtime' => false,
                    'employee_mobile_notifications' => true,
                    'email_summaries' => false,
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No need to reverse data population
        // The column drops in the previous migration will handle cleanup
    }
};
