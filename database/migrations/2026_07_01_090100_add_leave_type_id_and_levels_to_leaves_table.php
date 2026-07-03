<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leaves', function (Blueprint $table) {
            $table->foreignId('leave_type_id')->nullable()->after('leave_type')
                ->constrained('leave_types')->nullOnDelete();

            // which approval level (1-3) is currently pending
            $table->unsignedTinyInteger('current_level')->default(1)->after('status');

            // snapshot of how many levels were enabled when the chain was created
            $table->unsignedTinyInteger('total_levels')->nullable()->after('current_level');
        });
    }

    public function down(): void
    {
        Schema::table('leaves', function (Blueprint $table) {
            $table->dropConstrainedForeignId('leave_type_id');
            $table->dropColumn(['current_level', 'total_levels']);
        });
    }
};
