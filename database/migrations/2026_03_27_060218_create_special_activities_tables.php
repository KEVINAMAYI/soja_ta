<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('special_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type');
            $table->string('destination');
            $table->date('activity_date');
            $table->time('departure_time');
            $table->time('return_time');
            $table->string('emergency_contact')->nullable();
            $table->text('eligible_grades')->nullable();   // ← changed from json() to text()
            $table->string('lead_staff')->nullable();
            $table->string('transport')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['organization_id', 'activity_date']);
        });

        Schema::create('special_activity_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('special_activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['confirmed', 'departed', 'returned'])->default('confirmed');
            $table->timestamp('departed_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();
            $table->unique(['special_activity_id', 'employee_id'], 'sap_activity_employee_unique');
            $table->index('employee_id', 'sap_employee_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('special_activity_participants');
        Schema::dropIfExists('special_activities');
    }
};
