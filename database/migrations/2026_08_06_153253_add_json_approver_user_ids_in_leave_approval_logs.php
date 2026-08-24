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
        // Schema::table('leave_approval_logs', function (Blueprint $table) {
        //     $table->json('approver_user_ids')->after('approver_user_id');

        // });
    }

    public function down(): void
    {
        // Schema::table('leave_approval_logs', function (Blueprint $table) {
        //     $table->dropColumn(['approver_user_ids']);
        // });
    }
};
