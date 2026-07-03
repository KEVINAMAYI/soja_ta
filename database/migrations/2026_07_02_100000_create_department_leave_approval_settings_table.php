<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_leave_approval_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('department_id')->constrained()->onDelete('cascade')->unique();

            $table->boolean('enabled')->default(false);

            // Same 3-element shape as LeaveApprovalSettings::defaults()['levels'],
            // stored as text (json_encode/decode'd via the model's array cast)
            // rather than a native JSON column — matches organization_settings.value
            // and avoids native JSON column support differences across MySQL/MariaDB
            // versions in production.
            $table->text('levels');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_leave_approval_settings');
    }
};
