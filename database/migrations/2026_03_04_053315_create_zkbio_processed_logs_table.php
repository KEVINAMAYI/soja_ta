<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zkbio_processed_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('log_id')->primary(); // ZKBio logId — unique globally
            $table->string('device_sn', 100);
            $table->string('pin', 50)->nullable();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->timestamp('processed_at')->useCurrent();

            $table->index('device_sn');
            $table->index('pin');
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zkbio_processed_logs');
    }
};
