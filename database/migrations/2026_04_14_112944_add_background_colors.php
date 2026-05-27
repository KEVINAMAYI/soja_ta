<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('sidebar_bg_color', 7)->nullable()->default('#fffff');
            $table->string('page_bg_color', 7)->nullable()->default('#fffff');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['sidebar_bg_color', 'page_bg_color']);
        });
    }
};
