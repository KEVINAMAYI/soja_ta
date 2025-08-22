<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AttendanceSeeder;

class SeedAttendance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:seed';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed missing attendance records for employees based on shifts';

    protected AttendanceSeeder $seeder;

    public function __construct(AttendanceSeeder $seeder)
    {
        parent::__construct();
        $this->seeder = $seeder;
    }

    public function handle()
    {
        $this->info('Seeding attendance records...');
        $this->seeder->seedMissingAttendanceRecords();
        $this->info('Attendance seeding completed successfully.');
    }
}
