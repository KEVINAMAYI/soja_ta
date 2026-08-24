<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('level_approvers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_approval_log_id')->constrained()->onDelete('cascade');
            $table->foreignId('level_approver_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['leave_approval_log_id', 'level_approver_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('level_approvers');
    }
};
