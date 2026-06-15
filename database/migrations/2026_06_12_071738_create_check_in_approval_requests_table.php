<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('check_in_approval_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->index();
            $table->foreignId('employee_id')->index();

            // NOTE: no attendance_id at creation — the attendance record
            // does NOT exist yet. It is only created on approval.

            $table->date('date')->index();
            $table->dateTime('check_in_time'); // the actual scan time

            // ── Full payload needed to finalize the check-in on approval ──
            $table->unsignedInteger('minutes_late')->default(0);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->foreignId('device_id')->nullable();
            $table->foreignId('work_location_id')->nullable();

            $table->boolean('is_late_checkin')->default(false);
            $table->boolean('within_grace_period')->default(false);

            $table->time('expected_check_in_time')->nullable();
            $table->time('grace_period_end_time')->nullable();
            $table->time('expected_check_out_time')->nullable();
            $table->time('early_checkout_threshold_time')->nullable();

            // pending | approved | rejected
            $table->string('status')->default('pending')->index();

            // which window is currently active: 1, 2 or 3
            $table->unsignedTinyInteger('current_window')->default(1);

            $table->dateTime('submitted_at');
            $table->dateTime('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable();

            // set once the resulting attendance record is created on approval
            $table->foreignId('attendance_id')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_in_approval_requests');
    }
};
