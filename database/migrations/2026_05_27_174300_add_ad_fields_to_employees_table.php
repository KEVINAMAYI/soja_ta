<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('ad_employee_id')->nullable()->after('ad_object_id'); // AD employeeId e.g. M1ALI748
            $table->string('division')->nullable()->after('department_id');
            $table->string('section')->nullable()->after('division');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['ad_employee_id', 'division', 'section']);
        });
    }
};
