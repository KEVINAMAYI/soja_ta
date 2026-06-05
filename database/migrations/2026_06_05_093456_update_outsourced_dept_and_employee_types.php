<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1. Add the column
        Schema::table('employees', function (Blueprint $table) {
            $table->string('employee_type')->nullable()->after('employee_title');
        });

        // 2. Rename "Contract Staff" department → "Outsourced"
        DB::table('departments')
            ->where('name', 'Contract Staff')
            ->update(['name' => 'Outsourced']);

        // 3. Default everyone to COSMOS
        DB::table('employees')->update(['employee_type' => 'COSMOS']);

        // 4. Override employees in the Outsourced department
        DB::table('employees')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('departments')
                    ->whereColumn('departments.id', 'employees.department_id')
                    ->where('departments.name', 'Outsourced');
            })
            ->update(['employee_type' => 'Outsourced']);
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('employee_type');
        });

        DB::table('departments')
            ->where('name', 'Outsourced')
            ->update(['name' => 'Contract Staff']);
    }
};
