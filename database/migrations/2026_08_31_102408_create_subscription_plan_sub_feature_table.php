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
        Schema::create('subscription_plan_sub_feature', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sub_feature_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->timestamps();

            $table->unique(['subscription_plan_id', 'sub_feature_id'], 'plan_sub_feature_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_plan_sub_feature');
    }
};
