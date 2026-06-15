<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // True while a late check-in is awaiting approval.
            // status will be set to 'pending_approval' for the same period.
            $table->boolean('awaiting_approval')->default(false)->after('is_break_checkout');

            $table->foreignId('check_in_approval_request_id')->nullable()->after('awaiting_approval');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['awaiting_approval', 'check_in_approval_request_id']);
        });
    }
};
