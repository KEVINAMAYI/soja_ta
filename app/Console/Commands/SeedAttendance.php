<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AttendanceSeeder;
use Carbon\Carbon;

class SeedAttendance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:seed
        {--date= : Backfill a single past date (Y-m-d), re-evaluated against the current real time}
        {--days= : Backfill the last N days including today (today uses live seeding; earlier days are backfilled)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed/refresh attendance records for employees based on shifts';

    protected AttendanceSeeder $seeder;

    public function __construct(AttendanceSeeder $seeder)
    {
        parent::__construct();
        $this->seeder = $seeder;
    }

    public function handle()
    {
        if ($date = $this->option('date')) {
            $this->backfillDay(Carbon::parse($date));
            return;
        }

        if ($days = $this->option('days')) {
            $today = now()->toDateString();
            for ($i = (int) $days - 1; $i >= 0; $i--) {
                $day = now()->copy()->subDays($i)->startOfDay();
                if ($day->toDateString() === $today) {
                    $this->info("Seeding attendance for {$day->toDateString()} (live)...");
                    $this->seeder->seedMissingAttendanceRecords();
                } else {
                    $this->backfillDay($day);
                }
            }
            $this->info('Backfill completed successfully.');
            return;
        }

        $this->info('Seeding attendance records...');
        $this->seeder->seedMissingAttendanceRecords();
        $this->info('Attendance seeding completed successfully.');
    }

    /**
     * Re-evaluates a past date's attendance row against the real current time
     * (not end-of-day) — a night shift that started on $day has, by the time
     * the real clock reaches today, definitely closed its window, so this
     * resolves its final status instead of finding it "still open".
     */
    private function backfillDay(Carbon $day): void
    {
        $this->info("Backfilling attendance for {$day->toDateString()}...");
        $this->seeder->seedMissingAttendanceRecords(null, $day->copy()->startOfDay(), now());
    }
}
