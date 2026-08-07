<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->after('department_id')
                ->constrained()->nullOnDelete();
            $table->foreignId('section_id')->nullable()->after('section')
                ->constrained()->nullOnDelete();
            $table->foreignId('subsection_id')->nullable()->after('section_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['unit_id']);
            $table->dropForeign(['section_id']);
            $table->dropForeign(['subsection_id']);
            $table->dropColumn(['unit_id', 'section_id', 'subsection_id']);
        });
    }
};
