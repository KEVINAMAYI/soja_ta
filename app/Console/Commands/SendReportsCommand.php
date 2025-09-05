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

        # for testing monthly
        # Pretend it's 1st/2nd/3rd... September 2025 at 09:00
        # php artisan reports:send --now="2025-09-01 09:00:00"

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
                'frequency'  => $setting->frequency,
                'tzNow'      => $tzNow->toDateTimeString(),
                'reportTime' => $setting->time,
                'result'     => $shouldRun,
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
        // interpret "report time" in the user’s timezone
        $reportTime = Carbon::parse($setting->time, $setting->timezone);

        switch ($setting->frequency) {
            case 'daily':
                return $tzNow->format('H:i') === $reportTime->format('H:i');

            case 'weekly':
                return $tzNow->isSameDayOfWeek($setting->day_of_week ?? 'Monday')
                    && $tzNow->format('H:i') === $reportTime->format('H:i');

            case 'monthly':
                return $tzNow->day === 5
                    && $tzNow->format('H:i') === $reportTime->format('H:i');

            default:
                return false;
        }
    }
}
