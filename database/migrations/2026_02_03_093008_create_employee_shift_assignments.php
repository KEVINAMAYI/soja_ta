<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Create pivot table for employee-shift assignments
        Schema::create('employee_shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->integer('priority')->default(0); // Higher = more priority
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->timestamps();

            // Ensure unique combination
            $table->unique(['employee_id', 'shift_id']);
        });

        // Modify employees table
        Schema::table('employees', function (Blueprint $table) {
            // Make shift_id nullable (we'll use the pivot table instead)
            $table->foreignId('shift_id')->nullable()->change();

            // Add current active shift tracking
            $table->foreignId('current_shift_id')->nullable()->constrained('shifts')->nullOnDelete();

            // Track last shift change
            $table->timestamp('last_shift_change_at')->nullable();

            // Cooldown period in minutes (can be overridden per employee)
            $table->integer('shift_change_cooldown_minutes')->default(240); // 4 hours default
        });

        // Modify attendances table to track which shift was used
        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('auto_shift_detected')->default(false);
            $table->text('shift_detection_log')->nullable(); // JSON log of detection process
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropForeign(['shift_id']);
            $table->dropColumn(['shift_id', 'auto_shift_detected', 'shift_detection_log']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['current_shift_id']);
            $table->dropColumn(['current_shift_id', 'last_shift_change_at', 'shift_change_cooldown_minutes']);
        });

        Schema::dropIfExists('employee_shift_assignments');
    }
};
