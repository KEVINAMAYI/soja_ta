<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->constrained()->onDelete('cascade');

            $table->string('code'); // stable slug, e.g. sick, offshift, annual, maternity...
            $table->string('name'); // display name, e.g. "Annual Leave"
            $table->string('icon')->nullable();

            // null = unlimited / not balance-tracked (e.g. unpaid, offshift)
            $table->decimal('annual_entitlement_days', 5, 1)->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
