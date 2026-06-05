<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 1 — Add shift classification columns to the shifts table.
 *
 * Copy this file to: database/migrations/
 * Then run: php artisan migrate
 *
 * Columns added:
 *   department_type  — admin | general | engineering
 *   shift_type       — admin | day | night | extended
 *   friday_end_time  — override end time for Fridays (nullable)
 *   is_overnight     — true when shift crosses midnight (e.g. 17:30→05:00)
 *   overtime_saturday — label for Saturday OT tier (default: 'ot1')
 *   overtime_sunday   — label for Sunday OT tier   (default: 'ot2')
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {

            if (!Schema::hasColumn('shifts', 'department_type')) {
                $table->enum('department_type', ['admin', 'general', 'engineering'])
                    ->nullable()
                    ->after('name')
                    ->comment('Department group this shift belongs to');
            }

            if (!Schema::hasColumn('shifts', 'shift_type')) {
                $table->enum('shift_type', ['admin', 'day', 'night', 'extended'])
                    ->nullable()
                    ->after('department_type')
                    ->comment('Shift variant: day / night / extended / admin');
            }

            if (!Schema::hasColumn('shifts', 'friday_end_time')) {
                $table->time('friday_end_time')
                    ->nullable()
                    ->after('end_time')
                    ->comment('Different end time on Fridays. NULL = same as end_time every day.');
            }

            if (!Schema::hasColumn('shifts', 'is_overnight')) {
                $table->boolean('is_overnight')
                    ->default(false)
                    ->after('friday_end_time')
                    ->comment('True when shift crosses midnight — addDay() applied to end_time.');
            }

            if (!Schema::hasColumn('shifts', 'overtime_saturday')) {
                $table->string('overtime_saturday', 10)
                    ->default('ot1')
                    ->after('is_overnight')
                    ->comment('OT tier label for Saturday work');
            }

            if (!Schema::hasColumn('shifts', 'overtime_sunday')) {
                $table->string('overtime_sunday', 10)
                    ->default('ot2')
                    ->after('overtime_saturday')
                    ->comment('OT tier label for Sunday work');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn([
                'department_type',
                'shift_type',
                'friday_end_time',
                'is_overnight',
                'overtime_saturday',
                'overtime_sunday',
            ]);
        });
    }
};
