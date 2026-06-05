<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedBigInteger('shift_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // If you want to revert to NOT NULL, you MUST decide what value to use.
            // For rollback, we assume 0 OR any default shift.
            $table->unsignedBigInteger('shift_id')->nullable(false)->default(0)->change();
        });
    }
};

