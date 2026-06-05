<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Clean any values not in the new enum before altering
        DB::statement("
            UPDATE attendances
            SET status = 'absent'
            WHERE status NOT IN (
                'clocked_in', 'clocked_out', 'unchecked_in',
                'absent', 'on_leave', 'off_shift', 'sick_off',
                'on_break', 'not_scheduled'
            )
        ");

        Schema::table('attendances', function (Blueprint $table) {
            $table->enum('status', [
                'clocked_in',
                'clocked_out',
                'unchecked_in',
                'absent',
                'on_leave',
                'off_shift',
                'sick_off',
                'on_break',
                'not_scheduled',
            ])->default('absent')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("UPDATE attendances SET status = 'clocked_in' WHERE status = 'on_break'");
        DB::statement("UPDATE attendances SET status = 'absent' WHERE status = 'not_scheduled'");

        Schema::table('attendances', function (Blueprint $table) {
            $table->enum('status', [
                'clocked_in',
                'clocked_out',
                'unchecked_in',
                'absent',
                'on_leave',
                'off_shift',
                'sick_off',
            ])->default('absent')->change();
        });
    }
};
