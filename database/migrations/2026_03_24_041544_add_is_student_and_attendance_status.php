<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add is_student flag — using raw SQL check instead of Schema::hasColumn()
        $exists = DB::select("
            SELECT COUNT(*) as count
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
            AND table_name = 'employees'
            AND column_name = 'is_student'
        ")[0]->count;

        if (!$exists) {
            Schema::table('employees', function (Blueprint $table) {
                $table->tinyInteger('is_student')
                    ->default(0)
                    ->after('employee_title');
            });
        }

        // 2. Backfill logic
        DB::statement("
            UPDATE employees e
            INNER JOIN organizations o ON o.id = e.organization_id
            SET e.is_student = 1
            WHERE o.is_student_record = 1
        ");

        // 3. Updated ENUM
        DB::statement("
            ALTER TABLE attendances
            MODIFY COLUMN status ENUM(
                'clocked_in',
                'clocked_out',
                'unchecked_in',
                'absent',
                'on_leave',
                'off_shift',
                'sick_off',
                'on_break',
                'not_scheduled',
                'on_trip',
                'with_parent',
                'signed_out',
                'boarding_in'
            ) NOT NULL DEFAULT 'absent'
        ");
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('is_student');
        });

        DB::statement("
            ALTER TABLE attendances
            MODIFY COLUMN status ENUM(
                'clocked_in',
                'clocked_out',
                'unchecked_in',
                'absent',
                'on_leave',
                'off_shift',
                'sick_off',
                'not_scheduled'
            ) NOT NULL DEFAULT 'absent'
        ");
    }
};
