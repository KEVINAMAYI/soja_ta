<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_approval_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('leave_id')->constrained()->onDelete('cascade');

            // 1, 2 or 3
            $table->unsignedTinyInteger('level_number');

            $table->string('approver_type'); // 'role' | 'user'
            $table->string('approver_role')->nullable();
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();

            // pending | approved | rejected | skipped
            $table->string('status')->default('pending')->index();

            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();

            $table->foreignId('actioned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_approval_logs');
    }
};
