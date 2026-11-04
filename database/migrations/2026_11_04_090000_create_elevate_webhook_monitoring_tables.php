<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failed_elevate_webhooks', function (Blueprint $table) {
            $table->id();
            // Keep rejected requests even when the URL names a nonexistent organisation.
            $table->string('organization_id', 32);
            $table->string('source_ip', 45)->nullable();
            $table->unsignedSmallInteger('status_code');
            $table->text('reason');
            $table->json('validation_errors')->nullable();
            $table->timestamp('created_at');
            $table->index(['organization_id', 'id']);
        });

        Schema::create('elevate_webhook_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->string('organization_id', 32);
            $table->date('date');
            $table->unsignedBigInteger('received')->default(0);
            $table->unsignedBigInteger('failed')->default(0);
            $table->unsignedBigInteger('last_notified_failed')->default(0);
            $table->unique(['organization_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('elevate_webhook_daily_stats');
        Schema::dropIfExists('failed_elevate_webhooks');
    }
};
