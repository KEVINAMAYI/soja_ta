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
        Schema::create('zkbio_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->date('sync_date')->index();
            $table->dateTime('synced_until');
            $table->dateTime('synced_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zkbio_sync_logs');
    }
};
