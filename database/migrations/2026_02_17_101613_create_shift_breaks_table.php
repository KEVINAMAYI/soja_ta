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
        Schema::create('shift_breaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->onDelete('cascade');
            $table->string('name'); // e.g., "Lunch Break", "Morning Break"
            $table->enum('type', ['paid', 'unpaid', 'flexible'])->default('unpaid');
            $table->time('window_start_time')->nullable(); // When break window starts
            $table->time('window_end_time')->nullable(); // When break window ends
            $table->integer('duration_minutes'); // Expected break duration
            $table->integer('max_duration_minutes')->nullable(); // Maximum allowed without penalty
            $table->enum('penalty_type', ['none', 'deduct_overtime', 'flag_review', 'auto_deduct'])->default('none');
            $table->boolean('require_punch')->default(false); // Require explicit punch out/in
            $table->boolean('notify_on_approaching')->default(false);
            $table->integer('notify_minutes_before')->nullable();
            $table->boolean('is_mandatory')->default(false);
            $table->integer('order')->default(0); // For sorting breaks
            $table->boolean('is_active')->default(true);
            $table->longText('metadata')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('shift_id');
            $table->index(['shift_id', 'is_active']);
            $table->index('order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shift_breaks');
    }
};
