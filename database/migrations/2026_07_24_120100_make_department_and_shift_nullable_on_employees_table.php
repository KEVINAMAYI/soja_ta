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
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropForeign(['shift_id']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->change();
            $table->foreignId('shift_id')->nullable()->change();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreign('department_id')->references('id')->on('departments')->cascadeOnDelete();
            $table->foreign('shift_id')->references('id')->on('shifts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropForeign(['shift_id']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable(false)->change();
            $table->foreignId('shift_id')->nullable(false)->change();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreign('department_id')->references('id')->on('departments')->cascadeOnDelete();
            $table->foreign('shift_id')->references('id')->on('shifts')->cascadeOnDelete();
        });
    }
};
