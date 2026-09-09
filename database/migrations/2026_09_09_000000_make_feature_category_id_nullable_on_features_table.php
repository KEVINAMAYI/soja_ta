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
        Schema::table('features', function (Blueprint $table) {
            $table->dropForeign(['feature_category_id']);
        });

        Schema::table('features', function (Blueprint $table) {
            $table->foreignId('feature_category_id')->nullable()->change();
            $table->foreign('feature_category_id')->references('id')->on('feature_categories')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('features', function (Blueprint $table) {
            $table->dropForeign(['feature_category_id']);
        });

        Schema::table('features', function (Blueprint $table) {
            $table->foreignId('feature_category_id')->nullable(false)->change();
            $table->foreign('feature_category_id')->references('id')->on('feature_categories')->cascadeOnDelete();
        });
    }
};
