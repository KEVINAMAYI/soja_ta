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
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('environment', ['sandbox', 'production'])->default('sandbox');
            // Non-secret display prefix, e.g. "ID_sandbox" - used to identify the key in the UI.
            $table->string('key_prefix');
            // Last 4 characters of the generated secret, for display purposes only (e.g. "•••• ab12").
            $table->string('last_four', 4);
            // sha256 hash of the full plaintext key - the plaintext is never stored.
            $table->string('key_hash')->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
