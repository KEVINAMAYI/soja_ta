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
        Schema::table('attendances', function (Blueprint $table) {
            $table->enum('status', ['clocked_in', 'clocked_out', 'unchecked_in', 'absent', 'on_leave','off_shift'])
                ->default('absent')
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->enum('status', ['clocked_in', 'clocked_out', 'unchecked_in', 'absent'])
                ->default('absent')
                ->change();
        });
    }
};
