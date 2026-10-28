<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('elevate_hr_webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('creator_name');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'id']);
        });

        DB::table('organization_settings')
            ->where('key', 'elevatehr_leave_webhook_token_hash')
            ->whereNotNull('value')
            ->orderBy('id')
            ->chunkById(500, function ($settings) {
                foreach ($settings as $setting) {
                    DB::table('elevate_hr_webhooks')->insert([
                        'organization_id' => $setting->organization_id,
                        'token_hash' => $setting->value,
                        'creator_name' => 'Legacy setup (creator unknown)',
                        'created_at' => $setting->updated_at ?? $setting->created_at,
                        'updated_at' => $setting->updated_at ?? $setting->created_at,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('elevate_hr_webhooks');
    }
};
