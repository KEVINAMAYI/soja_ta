<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── attendances ───────────────────────────────────────────────────────
        Schema::table('attendances', function (Blueprint $table) {

            if (!Schema::hasColumn('attendances', 'ot1_hours')) {
                $table->decimal('ot1_hours', 5, 2)->default(0)
                    ->after('overtime_hours')
                    ->comment('Saturday OT1 hours');
            }
            if (!Schema::hasColumn('attendances', 'ot2_hours')) {
                $table->decimal('ot2_hours', 5, 2)->default(0)
                    ->after('ot1_hours')
                    ->comment('Sunday OT2 hours');
            }
            if (!Schema::hasColumn('attendances', 'defined_hours')) {
                $table->decimal('defined_hours', 5, 2)->default(9)
                    ->after('ot2_hours')
                    ->comment('Always 9 per client requirement');
            }
            if (!Schema::hasColumn('attendances', 'exception_note')) {
                $table->text('exception_note')->nullable()->after('defined_hours');
            }
            if (!Schema::hasColumn('attendances', 'on_leave')) {
                $table->boolean('on_leave')->default(false)->after('exception_note');
            }
            if (!Schema::hasColumn('attendances', 'gate_pass')) {
                $table->boolean('gate_pass')->default(false)->after('on_leave');
            }
            if (!Schema::hasColumn('attendances', 'interpretation')) {
                $table->string('interpretation')->nullable()->after('gate_pass');
            }
        });

        // ── overtimes ─────────────────────────────────────────────────────────
        Schema::table('overtimes', function (Blueprint $table) {
            if (!Schema::hasColumn('overtimes', 'type')) {
                $table->enum('type', ['weekday', 'saturday', 'sunday'])
                    ->default('weekday')
                    ->after('hours');
            }
        });

        // ── employees ─────────────────────────────────────────────────────────
        Schema::table('employees', function (Blueprint $table) {
            if (!Schema::hasColumn('employees', 'employee_category')) {
                $table->enum('employee_category', ['Contract Staff', 'Intern', 'Attachee'])
                    ->nullable()
                    ->after('ad_employee_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn([
                'ot1_hours', 'ot2_hours', 'defined_hours',
                'exception_note', 'on_leave', 'gate_pass', 'interpretation',
            ]);
        });
        Schema::table('overtimes', function (Blueprint $table) {
            $table->dropColumn('type');
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('employee_category');
        });
    }
};
