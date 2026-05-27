<?php

// database/migrations/xxxx_xx_xx_xxxxxx_add_shift_status_to_employees_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddShiftStatusToEmployeesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->enum('shift_status', ['on_shift', 'off_shift'])->default('on_shift')->after('active');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('shift_status');
        });
    }
}
