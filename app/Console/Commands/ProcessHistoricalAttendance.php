<?php

namespace App\Console\Commands;

use App\Services\AttendanceSeeder;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ProcessHistoricalAttendance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:process-historical
                            {date? : The date to process (Y-m-d format)}
                            {--from= : Start date for range processing (Y-m-d)}
                            {--to= : End date for range processing (Y-m-d)}
                            {--org= : Organization ID to filter by}
                            {--days= : Number of days back from today}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process attendance records for previous dates to handle missed clock-outs and failed cron jobs';

    /**
     * Execute the console command.
     */
    public function handle(AttendanceSeeder $seeder)
    {
        $this->info('Starting historical attendance processing...');

        // Get organization ID if provided
        $orgId = $this->option('org');

        // Determine which dates to process
        $dates = $this->getDatesToProcess();

        if (empty($dates)) {
            $this->error('No valid dates to process. Please provide date, date range, or days option.');
            return 1;
        }

        $this->info('Processing ' . count($dates) . ' date(s)...');

        $progressBar = $this->output->createProgressBar(count($dates));
        $progressBar->start();

        foreach ($dates as $date) {
            $this->processDateWithContext($seeder, $date, $orgId);
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);
        $this->info('Historical attendance processing completed!');

        return 0;
    }

    /**
     * Process a single date with proper context
     */
    private function processDateWithContext(AttendanceSeeder $seeder, Carbon $date, ?int $orgId): void
    {
        // Temporarily override Carbon's "now" to the date we're processing
        Carbon::setTestNow($date->endOfDay());

        try {
            $seeder->seedMissingAttendanceRecords($orgId);

            $this->newLine();
            $this->line("✓ Processed: " . $date->format('Y-m-d'));
        } catch (\Exception $e) {
            $this->newLine();
            $this->error("✗ Failed: " . $date->format('Y-m-d') . " - " . $e->getMessage());
        } finally {
            // Reset Carbon's test time
            Carbon::setTestNow();
        }
    }

    /**
     * Determine which dates to process based on command arguments
     */
    private function getDatesToProcess(): array
    {
        $dates = [];

        // Option 1: Single date
        if ($this->argument('date')) {
            try {
                $dates[] = Carbon::parse($this->argument('date'));
            } catch (\Exception $e) {
                $this->error('Invalid date format. Use Y-m-d (e.g., 2024-01-15)');
                return [];
            }
        }
        // Option 2: Date range
        elseif ($this->option('from') && $this->option('to')) {
            try {
                $from = Carbon::parse($this->option('from'));
                $to = Carbon::parse($this->option('to'));

                if ($to->lessThan($from)) {
                    $this->error('End date must be after start date');
                    return [];
                }

                // Generate all dates in range
                $current = $from->copy();
                while ($current->lessThanOrEqualTo($to)) {
                    $dates[] = $current->copy();
                    $current->addDay();
                }
            } catch (\Exception $e) {
                $this->error('Invalid date format in range. Use Y-m-d (e.g., 2024-01-15)');
                return [];
            }
        }
        // Option 3: Number of days back
        elseif ($this->option('days')) {
            $days = (int) $this->option('days');
            if ($days < 1) {
                $this->error('Days must be a positive number');
                return [];
            }

            for ($i = 1; $i <= $days; $i++) {
                $dates[] = now()->subDays($i);
            }
        }

        return $dates;
    }
}
