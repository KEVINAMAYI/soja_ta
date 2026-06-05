<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('report_settings', function (Blueprint $table) {
            $table->timestamp('last_run_at')->nullable()->after('active');
            $table->timestamp('next_run_at')->nullable()->after('last_run_at');
        });
    }

    public function down(): void
    {
        Schema::table('report_settings', function (Blueprint $table) {
            $table->dropColumn(['last_run_at', 'next_run_at']);
        });
    }
};

