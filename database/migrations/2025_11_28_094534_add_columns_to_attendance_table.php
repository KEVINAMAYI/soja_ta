<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {

            // Auto clock-out settings
            $table->boolean('auto_clocked_out')->default(false);
            $table->longText('auto_clocked_out_reason')->nullable();
            $table->boolean('overtime_warning_sent')->default(false);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn([
                'auto_clocked_out',
                'auto_clocked_out_reason',
                'overtime_warning_sent',
            ]);
        });
    }
};
