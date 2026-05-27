<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("
            ALTER TABLE employees
            MODIFY shift_status ENUM('on_shift', 'off_shift', 'sick_off')
            DEFAULT 'on_shift'
        ");
    }

    public function down(): void
    {
        // revert back to original enum
        DB::statement("
            ALTER TABLE employees
            MODIFY shift_status ENUM('on_shift', 'off_shift')
            DEFAULT 'on_shift'
        ");
    }
};
