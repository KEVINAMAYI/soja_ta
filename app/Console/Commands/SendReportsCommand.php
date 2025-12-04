<?php

namespace App\Console\Commands;

use App\Models\ReportSetting;
use App\Jobs\SendReportJob;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\Command as CommandAlias;

class SendReportsCommand extends Command
{
    protected $signature = 'reports:send {--now=}';
    protected $description = 'Check report settings and send reports to recipients';

    public function handle()
    {
        // allow injecting a fake "now" for testing
        $now = $this->option('now')
            ? Carbon::parse($this->option('now'))
            : Carbon::now();

        // fetch active report settings
        $settings = ReportSetting::active()->get();

        foreach ($settings as $setting) {
            $tzNow = $now->copy()->setTimezone($setting->timezone ?? config('app.timezone'));

            // check if report should run
            $shouldRun = $this->shouldRun($setting, $tzNow);

            Log::info('ShouldRun evaluated', [
                'setting_id' => $setting->id,
                'frequency' => $setting->frequency,
                'day_of_week' => $setting->day_of_week,
                'tzNow' => $tzNow->toDateTimeString(),
                'tzNow_day' => $tzNow->format('l'),
                'reportTime' => $setting->time,
                'result' => $shouldRun,
            ]);

            if ($shouldRun) {
                dispatch(new SendReportJob($setting->id, $setting->organization_id));
                $this->info("Queued report for {$setting->email} ({$setting->report_type})");
            }
        }

        return CommandAlias::SUCCESS;
    }

    private function shouldRun(ReportSetting $setting, Carbon $tzNow): bool
    {

        // skip inactive reports
        if (!$setting->active) {
            return false;
        }

        // interpret "report time" in the user's timezone
        $reportTime = Carbon::parse($setting->time, $setting->timezone);

        switch ($setting->frequency) {
            case 'daily':
                return $tzNow->format('H:i') === $reportTime->format('H:i');

            case 'weekly':
                return $tzNow->format('l') === ($setting->day_of_week ?? 'Monday')
                    && $tzNow->format('H:i') === $reportTime->format('H:i');

            case 'monthly':
                // If day_of_week is set, run on the LAST occurrence of that day each month
                // This ensures we have a complete month of data to report on
                if ($setting->day_of_week) {
                    $endOfMonth = $tzNow->copy()->endOfMonth();
                    $lastOccurrence = $endOfMonth->previous($setting->day_of_week);

                    // If the last occurrence is actually in the next month, use end of current month
                    if ($lastOccurrence->month !== $tzNow->month) {
                        $lastOccurrence = $endOfMonth;
                    }

                    return $tzNow->isSameDay($lastOccurrence)
                        && $tzNow->format('H:i') === $reportTime->format('H:i');
                }

                // Default: run on the last day of the month
                return $tzNow->isLastOfMonth()
                    && $tzNow->format('H:i') === $reportTime->format('H:i');

            default:
                return false;
        }
    }
}

/*
=============================================================================
TEST SCENARIOS - Copy and paste these commands to test
=============================================================================

Assuming you have a report setting with:
- frequency: 'monthly'
- day_of_week: 'Wednesday'
- time: '09:00'

# Test 1: First Wednesday of September (should NOT send)
php artisan reports:send --now="2025-09-03 09:00:00"
Expected: NO report sent (too early in the month)

# Test 2: Last Wednesday of September (SHOULD send)
php artisan reports:send --now="2025-09-24 09:00:00"
Expected: Report SENT with data from previous ~30 days

# Test 3: First Wednesday of October (should NOT send)
php artisan reports:send --now="2025-10-01 09:00:00"
Expected: NO report sent (too early in the month)

# Test 4: Last Wednesday of October (SHOULD send)
php artisan reports:send --now="2025-10-29 09:00:00"
Expected: Report SENT with data from previous ~30 days

---

For monthly reports WITHOUT day_of_week set:

# Test 5: Last day of September (SHOULD send)
php artisan reports:send --now="2025-09-30 09:00:00"
Expected: Report SENT with full previous month data

# Test 6: Last day of February (SHOULD send)
php artisan reports:send --now="2025-02-28 09:00:00"
Expected: Report SENT with full previous month data

---

For weekly reports:

# Test 7: Configured day (e.g., Friday)
php artisan reports:send --now="2025-09-05 09:00:00"
Expected: Report SENT with data from past 7 days

# Test 8: Different day (e.g., Monday when Friday is configured)
php artisan reports:send --now="2025-09-01 09:00:00"
Expected: NO report sent

---

For daily reports:

# Test 9: Any day at configured time
php artisan reports:send --now="2025-09-15 09:00:00"
Expected: Report SENT with today's data

# Test 10: Wrong time
php artisan reports:send --now="2025-09-15 14:00:00"
Expected: NO report sent (if time is set to 09:00)

=============================================================================
*/
