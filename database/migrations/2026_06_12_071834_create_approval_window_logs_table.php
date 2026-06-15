<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_window_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('check_in_approval_request_id')->index();

            // 1, 2 or 3
            $table->unsignedTinyInteger('window_number');

            $table->string('approver_role'); // e.g. Line Manager, HR Manager, Department Head
            $table->unsignedInteger('timeout_minutes');

            // action to take on timeout: approve | reject | escalate
            $table->string('on_timeout_action');

            $table->dateTime('opened_at');
            $table->dateTime('expires_at');
            $table->dateTime('closed_at')->nullable();

            // pending | approved | rejected | escalated | expired
            $table->string('status')->default('pending')->index();

            $table->foreignId('actioned_by')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_window_logs');
    }
};
