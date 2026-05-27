<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('primary_color', 7)->default('#072639')->after('logo_path');
            $table->integer('logo_height')->default(60)->after('primary_color');
            $table->integer('logo_width')->default(200)->after('logo_height');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['primary_color', 'logo_height', 'logo_width']);
        });
    }
};
