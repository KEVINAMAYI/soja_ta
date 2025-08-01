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
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('type'); // e.g., Meal, Break
            $table->integer('standard_duration')->nullable(); // in minutes
            $table->decimal('cost', 8, 2)->nullable();
            $table->time('available_from')->nullable();
            $table->time('available_to')->nullable();
            $table->json('days_available')->nullable(); // ["Mon", "Tue", "Wed"]
            $table->enum('clocking_type', ['auto', 'manual'])->default('manual');
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
