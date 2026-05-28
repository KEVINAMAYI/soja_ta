<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->integer('lost_minutes')->default(0)->after('excess_break_minutes');
            $table->integer('late_checkin_lost_minutes')->default(0)->after('lost_minutes');
            $table->integer('break_lost_minutes')->default(0)->after('late_checkin_lost_minutes');
            $table->integer('enforced_break_minutes')->default(0)->after('break_lost_minutes');
            $table->boolean('break_enforced')->default(false)->after('enforced_break_minutes');
            $table->boolean('missed_break_return')->default(false)->after('break_enforced');
            $table->text('lost_hours_breakdown')->nullable()->after('missed_break_return');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn([
                'lost_minutes',
                'late_checkin_lost_minutes',
                'break_lost_minutes',
                'enforced_break_minutes',
                'break_enforced',
                'missed_break_return',
                'lost_hours_breakdown',
            ]);
        });
    }
};
