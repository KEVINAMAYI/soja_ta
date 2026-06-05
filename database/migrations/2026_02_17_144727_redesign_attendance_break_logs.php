<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('attendance_break_logs', function (Blueprint $table) {
            $table->foreignId('shift_break_id')->nullable()->change();
            $table->boolean('is_auto_detected')->default(true)->after('shift_break_id');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
