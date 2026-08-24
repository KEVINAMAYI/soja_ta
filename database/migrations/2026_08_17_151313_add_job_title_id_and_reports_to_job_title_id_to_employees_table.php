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
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignIdFor(\App\Models\JobTitle::class, 'job_title_id')->nullable()->after('phone');
            $table->foreignIdFor(\App\Models\JobTitle::class, 'reports_to_job_title_id')->nullable()->after('job_title_id');
            //
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('job_title_id');
            $table->dropColumn('reports_to_job_title_id');
        });
    }
};
