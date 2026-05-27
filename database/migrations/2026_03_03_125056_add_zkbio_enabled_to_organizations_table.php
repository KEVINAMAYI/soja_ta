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
            $table->boolean('zkbio_enabled')->default(false)->after('id');
            $table->string('zkbio_device_sn')->nullable()->after('zkbio_enabled');
            $table->string('zkbio_base_url')->nullable()->after('zkbio_device_sn');
            $table->string('zkbio_access_token')->nullable()->after('zkbio_base_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            //
        });
    }
};
