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
        Schema::table('organizations', function (Blueprint $table) {
            $table->unsignedInteger('max_locations')->nullable()->after('subscription_plan_id');
            $table->unsignedInteger('max_devices')->nullable()->after('max_locations');
            $table->string('accent_color', 7)->nullable()->after('primary_color');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['max_locations', 'max_devices', 'accent_color']);
        });
    }
};
