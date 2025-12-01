<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {

            // Late check-in tracking
            $table->boolean('is_late_checkin')->default(false)->after('check_in_time');
            $table->integer('minutes_late')->default(0)->after('is_late_checkin');

            // Early checkout tracking
            $table->boolean('is_early_checkout')->default(false)->after('check_out_time');
            $table->integer('minutes_early')->default(0)->after('is_early_checkout');

            // Grace period info
            $table->boolean('within_grace_period')->default(false)->after('minutes_late');

            // Timestamps for comparison
            $table->time('expected_check_in_time')->nullable()->after('within_grace_period');
            $table->time('grace_period_end_time')->nullable()->after('expected_check_in_time');
            $table->time('expected_check_out_time')->nullable()->after('grace_period_end_time');
            $table->time('early_checkout_threshold_time')->nullable()->after('expected_check_out_time');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn([
                'is_late_checkin',
                'minutes_late',
                'is_early_checkout',
                'minutes_early',
                'within_grace_period',
                'expected_check_in_time',
                'grace_period_end_time',
                'expected_check_out_time',
                'early_checkout_threshold_time',
            ]);
        });
    }
};
