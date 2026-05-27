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
        Schema::table('attendances', function (Blueprint $table) {
            // Add break-related columns
            $table->integer('total_break_minutes')->default(0)->after('overtime_hours');
            $table->integer('paid_break_minutes')->default(0)->after('total_break_minutes');
            $table->integer('excess_break_minutes')->default(0)->after('paid_break_minutes');

            // Add indexes
            $table->index('total_break_minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn([
                'total_break_minutes',
                'paid_break_minutes',
                'excess_break_minutes',
            ]);
        });
    }
};
