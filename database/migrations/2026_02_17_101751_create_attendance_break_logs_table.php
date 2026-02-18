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
        Schema::create('attendance_break_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->constrained()->onDelete('cascade');
            $table->foreignId('shift_break_id')->constrained()->onDelete('cascade');
            $table->timestamp('break_start_time')->nullable();
            $table->timestamp('break_end_time')->nullable();
            $table->integer('actual_duration_minutes')->nullable();
            $table->integer('excess_minutes')->default(0); // Minutes over allowed duration
            $table->boolean('is_compliant')->default(true); // Within allowed duration
            $table->boolean('is_taken')->default(false); // Whether break was taken
            $table->enum('status', ['pending', 'in_progress', 'completed', 'skipped', 'exceeded'])->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('attendance_id');
            $table->index('shift_break_id');
            $table->index(['attendance_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_break_logs');
    }
};
