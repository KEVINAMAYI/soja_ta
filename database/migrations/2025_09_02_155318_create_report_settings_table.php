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
        Schema::create('report_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('email');
            $table->string('report_type');
            $table->enum('frequency', ['daily', 'weekly', 'monthly'])->default('weekly');
            $table->time('time')->default('09:00:00');
            $table->string('day_of_week')->nullable(); // e.g., Monday
            $table->string('timezone')->default('UTC');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_settings');
    }
};
