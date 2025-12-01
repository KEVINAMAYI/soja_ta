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
            // Grace period settings
            $table->boolean('grace_period_enabled')->default(true)->after('warning_time_minutes');
            $table->integer('grace_period_minutes')->default(15)->after('grace_period_enabled');

            // Late check-in tracking
            $table->boolean('track_late_checkin')->default(true)->after('grace_period_minutes');
            $table->boolean('notify_on_late_checkin')->default(false)->after('track_late_checkin');

            // Early check-out tracking (optional but useful)
            $table->boolean('track_early_checkout')->default(true)->after('notify_on_late_checkin');
            $table->integer('early_checkout_threshold_minutes')->default(15)->after('track_early_checkout');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn([
                'grace_period_enabled',
                'grace_period_minutes',
                'track_late_checkin',
                'notify_on_late_checkin',
                'track_early_checkout',
                'early_checkout_threshold_minutes',
            ]);
        });
    }
};
