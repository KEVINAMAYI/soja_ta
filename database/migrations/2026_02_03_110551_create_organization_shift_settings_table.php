<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('organization_shift_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Main control: Enable/Disable auto shift detection
            $table->boolean('allow_auto_shift_detection')->default(false);

            // Shift change cooldown (prevent rapid switching)
            $table->integer('shift_change_cooldown_minutes')->default(240); // 4 hours

            // Require manager approval for manual shift changes
            $table->boolean('require_approval_for_manual_shift_change')->default(false);

            // Allow employees to manually select shift during check-in
            $table->boolean('allow_manual_shift_selection')->default(false);

            // Minimum score required for auto-detection to succeed
            $table->integer('auto_detection_minimum_score')->default(40);

            $table->timestamps();

            $table->unique('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_shift_settings');
    }
};
