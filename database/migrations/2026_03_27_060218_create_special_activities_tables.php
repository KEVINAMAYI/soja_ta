<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ── Main activities table ──────────────────────────────────
        Schema::create('special_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type');            // field_trip | sports | cultural | academic | community | other
            $table->string('destination');
            $table->date('activity_date');
            $table->time('departure_time');
            $table->time('return_time');
            $table->string('emergency_contact')->nullable();
            $table->json('eligible_grades')->nullable();   // ["Grade 5","Grade 6"]
            $table->string('lead_staff')->nullable();
            $table->string('transport')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['organization_id', 'activity_date']);
        });

        // ── Participants (students assigned to an activity) ────────
        Schema::create('special_activity_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('special_activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            /*
             * STATUS FLOW (driven by biometric scans):
             *
             *   confirmed  →  (departure scan at gate) →  departed
             *   departed   →  (return scan at gate)    →  returned
             *
             * The ZKBio device at the school GATE handles this:
             *   - First scan of the day while activity is live  → departed
             *   - Subsequent scan while activity is live         → returned
             *
             * For students NOT on an activity, the existing
             * clocked_in / clocked_out Attendance logic still applies.
             */
            $table->enum('status', ['confirmed', 'departed', 'returned'])->default('confirmed');
            $table->timestamp('departed_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();

            // After (explicit short name = well under 64 chars)
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
