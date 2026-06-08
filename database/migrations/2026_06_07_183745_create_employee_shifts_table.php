<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Creates employee_shifts pivot table.
 * Allows employees to be assigned to multiple shifts (e.g. Day + Night).
 *
 * After running:
 *   php artisan migrate
 *
 * Then run the data migration at the bottom to copy existing shift_id assignments.
 */
return new class extends Migration
{
    public function up(): void
    {

        Schema::dropIfExists('employee_shifts');

        Schema::create('employee_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->onDelete('cascade');
            $table->foreignId('shift_id')->constrained()->onDelete('cascade');
            $table->boolean('is_primary')->default(false)->comment('Primary shift for this employee');
            $table->timestamps();

            $table->unique(['employee_id', 'shift_id']); // no duplicates
            $table->index(['employee_id', 'is_primary']);
        });

        // Migrate existing shift_id assignments to the pivot table
        DB::statement("
            INSERT INTO employee_shifts (employee_id, shift_id, is_primary, created_at, updated_at)
            SELECT id, shift_id, 1, NOW(), NOW()
            FROM employees
            WHERE shift_id IS NOT NULL
              AND deleted_at IS NULL
            ON DUPLICATE KEY UPDATE is_primary = 1
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_shifts');
    }
};
