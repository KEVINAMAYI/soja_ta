<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            // Duration tracking
            $table->decimal('duration_hours', 4, 2)->default(8.00)->after('end_time');

            // Overtime settings
            $table->boolean('overtime_enabled')->default(true)->after('overtime_rate');
            $table->decimal('max_overtime_hours', 4, 2)->default(2.00)->after('overtime_enabled');

            // Auto clock-out settings
            $table->boolean('auto_clock_out')->default(false)->after('max_overtime_hours');
            $table->integer('warning_time_minutes')->default(30)->after('auto_clock_out');

            // Shift pattern (stores pattern type: weekdays, weekends, daily, rotating, custom)
            $table->string('pattern_type')->default('weekdays')->after('warning_time_minutes');

            // Pattern days stored as JSON: ["Mon", "Tue", "Wed", "Thu", "Fri"]
            $table->json('pattern_days')->nullable()->after('pattern_type');

            // Notification settings
            $table->boolean('notify_managers_overtime')->default(false)->after('pattern_days');
            $table->boolean('employee_mobile_notifications')->default(true)->after('notify_managers_overtime');
            $table->boolean('email_summaries')->default(false)->after('employee_mobile_notifications');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn([
                'duration_hours',
                'overtime_enabled',
                'max_overtime_hours',
                'auto_clock_out',
                'warning_time_minutes',
                'pattern_type',
                'pattern_days',
                'notify_managers_overtime',
                'employee_mobile_notifications',
                'email_summaries'
            ]);
        });
    }
};
