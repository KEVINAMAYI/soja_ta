<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddOffShiftColumnsToEmployeesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->date('start_off_shift_date')->nullable(); // Nullable in case no off-shift dates are set yet
            $table->date('end_off_shift_date')->nullable();   // Nullable in case no off-shift dates are set yet
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('start_off_shift_date');
            $table->dropColumn('end_off_shift_date');
        });
    }
}
