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
        
        Schema::table('leave_approval_logs', function (Blueprint $table) {
            if (Schema::hasColumn('leave_approval_logs', 'approver_user_ids')) {
                $table->dropColumn('approver_user_ids');
            }
            $table->text('approver_user_ids')->nullable()->after('approver_user_id');
            //
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('table_leave_approval_logs', function (Blueprint $table) {
            
            //
        });
    }
};
