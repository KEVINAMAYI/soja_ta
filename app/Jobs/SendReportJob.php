<?php

namespace App\Jobs;

use App\Mail\ReportMail;
use App\Models\ReportSetting;
use App\Services\ReportGeneratorService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use App\Services\AttendanceReportService;

class SendReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $settingId;
    public int $organizationId;

    /**
     * Pass only IDs (serializable) to avoid queue serialization issues.
     */
    public function __construct(int $settingId, int $organizationId)
    {
        $this->settingId = $settingId;
        $this->organizationId = $organizationId;

    }

    public function handle()
    {
        try {

            $setting = ReportSetting::find($this->settingId);

            if (!$setting) {
                Log::warning('ReportSetting not found', [
                    'setting_id' => $this->settingId,
                ]);
                return;
            }

            Log::info('SendReportJob started', [
                'email' => $setting->email,
                'report_type' => $setting->report_type,
                'organization_id' => $this->organizationId,
            ]);

            $reportFile = $this->generateReport($setting);

            if (!$reportFile) {
                Log::warning('Report generation returned null', [
                    'report_type' => $setting->report_type,
                    'organization_id' => $this->organizationId,
                ]);
            } else {
                Log::info('Report generated successfully', [
                    'file_path' => $reportFile,
                ]);
            }

            Mail::to($setting->email)->send(new ReportMail($setting, $reportFile['path']));

            // Update last_run_at and next_run_at
            $now = Carbon::now();
            $setting->update([
                'last_run_at' => $now,
                'next_run_at' => $this->calculateNextRun($setting, $now),
            ]);

        } catch (\Throwable $e) {
            Log::error('Report sending failed', [
                'setting_id' => $this->settingId,
                'organization_id' => $this->organizationId,
                'error' => $e->getMessage(),
                'stack' => $e->getTraceAsString(),
            ]);

            throw $e; // rethrow so Laravel marks it failed
        }
    }

    private function generateReport(ReportSetting $setting, array $ids = []): ?array
    {
        try {
            $reportService = app(AttendanceReportService::class);
            $reportGenerator = app(ReportGeneratorService::class);

            $type = $setting->report_type;
            $frequency = $setting->frequency;


            // Determine date ranges based on frequency
            switch ($frequency) {
                case 'daily':
                    $startDate = now()->toDateString();
                    $endDate = now()->toDateString();
                    break;

                case 'weekly':
                    // Get the week ending on the configured day_of_week
                    $dayOfWeek = $setting->day_of_week ?? 'Monday';
                    $endOfWeek = now()->previous($dayOfWeek);

                    // If today is the configured day and we haven't passed the report time yet,
                    // use last occurrence of that day
                    if (now()->isSameDay($endOfWeek)) {
                        $endOfWeek = now();
                    }

                    $startDate = $endOfWeek->copy()->subDays(6)->toDateString();
                    $endDate = $endOfWeek->toDateString();
                    break;

                case 'monthly':
                    // If day_of_week is set, calculate based on last occurrence in previous month
                    if ($setting->day_of_week) {
                        $dayOfWeek = $setting->day_of_week;

                        // Get last occurrence of the specified day in the previous month
                        $lastMonth = now()->subMonth();
                        $lastOccurrence = $lastMonth->endOfMonth()->previous($dayOfWeek);

                        // If that day is actually in the month before, get the last one in our target month
                        if ($lastOccurrence->month !== $lastMonth->month) {
                            $lastOccurrence = $lastMonth->endOfMonth();
                        }

                        $startDate = $lastOccurrence->copy()->subDays(29)->toDateString(); // ~30 days
                        $endDate = $lastOccurrence->toDateString();
                    } else {
                        // Default: Previous full month
                        $startDate = now()->subMonth()->startOfMonth()->toDateString();
                        $endDate = now()->subMonth()->endOfMonth()->toDateString();
                    }
                    break;

                default:
                    $startDate = now()->toDateString();
                    $endDate = now()->toDateString();
            }

            // Generate report based on type
            switch ($type) {
                case 'attendance':
                    // Always use getDaily for attendance reports
                    $attendances = $reportService->getDaily(
                        $this->organizationId,
                        $ids,
                        $startDate,
                        $endDate,
                        null // status
                    );
                    $view = 'exports.attendance.daily';
                    $reportName = 'attendance';
                    break;

                case 'timesheets':
                    // Always use getMonthly for timesheet reports
                    // Note: getMonthly signature needs all parameters
                    $attendances = $reportService->getMonthly(
                        $this->organizationId,
                        $ids,
                        $startDate,
                        $endDate,
                        null // department_id
                    );
                    $view = 'exports.attendance.monthly';
                    $reportName = 'timesheets';
                    break;

                case 'department':
                    // Always use getByDepartment for department reports
                    $attendances = $reportService->getByDepartment(
                        $this->organizationId,
                        $ids,
                        $startDate,
                        $endDate
                    );
                    $view = 'exports.attendance.department';
                    $reportName = 'department';
                    break;

                default:
                    Log::warning('Unknown report type', ['type' => $type]);
                    return null;
            }

            if ($attendances->isEmpty()) {
                Log::warning('No attendance records found', [
                    'organization_id' => $this->organizationId,
                    'type' => $type,
                    'frequency' => $frequency,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ]);
                return null;
            }

            $reportTitle = ucfirst($reportName) . ' Report - ' . ucfirst($frequency);

            return $reportGenerator->generate(
                $view,
                [
                    'title' => $reportTitle,
                    'date' => now()->format('d M Y, H:i'),
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                    'isExcel' => false,
                    'attendances' => $attendances,
                ],
                "{$reportName}-{$frequency}-report-" . now()->format('Y-m-d'),
                saveToDisk: true
            );

        } catch (\Throwable $e) {
            Log::error('Report generation failed', [
                'error' => $e->getMessage(),
                'stack' => $e->getTraceAsString(),
                'type' => $setting->report_type,
                'frequency' => $setting->frequency,
                'organization_id' => $this->organizationId,
            ]);
            return null;
        }
    }


    /**
     * Calculate next run datetime for this report.
     */
    private function calculateNextRun(ReportSetting $setting, Carbon $from): ?Carbon
    {
        $tzNow = $from->copy()->setTimezone($setting->timezone ?? config('app.timezone'));

        switch ($setting->frequency) {
            case 'daily':
                return $tzNow->addDay()->setTimeFromTimeString($setting->time);

            case 'weekly':
                $dayOfWeek = $setting->day_of_week ?? 'Monday';
                return $tzNow->copy()->next($dayOfWeek)->setTimeFromTimeString($setting->time);

            case 'monthly':
                if ($setting->day_of_week) {
                    $endOfMonth = $tzNow->copy()->endOfMonth();
                    $nextOccurrence = $endOfMonth->next($setting->day_of_week)->setTimeFromTimeString($setting->time);
                    if ($nextOccurrence->month !== $tzNow->month) {
                        $nextOccurrence = $endOfMonth->copy()->addMonth()->endOfMonth()->setTimeFromTimeString($setting->time);
                    }
                    return $nextOccurrence;
                }
                return $tzNow->copy()->addMonth()->endOfMonth()->setTimeFromTimeString($setting->time);

            default:
                return null;
        }
    }
}
