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

            Log::info('DEBUG: Report generation complete', [
                'reportFile' => $reportFile,
                'reportFile_type' => gettype($reportFile),
                'is_null' => is_null($reportFile),
                'is_array' => is_array($reportFile),
                'setting_id' => $this->settingId,
                'organization_id' => $this->organizationId,
            ]);

            // FIX: Check if report generation failed and exit early
            if (!$reportFile || !isset($reportFile['path'])) {
                Log::warning('Report generation failed - skipping email', [
                    'report_type' => $setting->report_type,
                    'organization_id' => $this->organizationId,
                    'report_file' => $reportFile,
                ]);

                // Still update scheduling even if report failed
                $now = Carbon::now();
                $nextRun = $this->calculateNextRun($setting, $now);

                $setting->update([
                    'last_run_at' => $now,
                    'next_run_at' => $nextRun,
                ]);

                return; // EXIT EARLY - Don't try to send email
            }

            Log::info('Report generated successfully', [
                'file_path' => $reportFile['path'],
            ]);

            // Only send email if report was generated successfully
            Mail::to($setting->email)->send(new ReportMail($setting, $reportFile['path']));

            // Update last_run_at and next_run_at
            $now = Carbon::now();
            $nextRun = $this->calculateNextRun($setting, $now);

            $setting->update([
                'last_run_at' => $now,
                'next_run_at' => $nextRun,
            ]);

            Log::info('Report sent successfully', [
                'email' => $setting->email,
                'next_run_at' => $nextRun,
            ]);

        } catch (\Throwable $e) {
            Log::error('Report sending failed', [
                'setting_id' => $this->settingId,
                'organization_id' => $this->organizationId,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'stack' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    private function generateReport(ReportSetting $setting, array $ids = []): ?array
    {
        try {
            $reportService = app(AttendanceReportService::class);
            $reportGenerator = app(ReportGeneratorService::class);

            $type = $setting->report_type;
            $frequency = $setting->frequency;

            Log::info('Generating report', [
                'type' => $type,
                'frequency' => $frequency,
                'organization_id' => $this->organizationId,
            ]);

            // Determine date ranges based on frequency
            switch ($frequency) {
                case 'daily':
                    $startDate = now()->toDateString();
                    $endDate = now()->toDateString();
                    break;

                case 'weekly':
                    $dayOfWeek = $setting->day_of_week ?? 'Monday';
                    $endOfWeek = now()->previous($dayOfWeek);

                    if (now()->isSameDay($endOfWeek)) {
                        $endOfWeek = now();
                    }

                    $startDate = $endOfWeek->copy()->subDays(6)->toDateString();
                    $endDate = $endOfWeek->toDateString();
                    break;

                case 'monthly':
                    if ($setting->day_of_week) {
                        $dayOfWeek = $setting->day_of_week;
                        $lastMonth = now()->subMonth();
                        $lastOccurrence = $lastMonth->endOfMonth()->previous($dayOfWeek);

                        if ($lastOccurrence->month !== $lastMonth->month) {
                            $lastOccurrence = $lastMonth->endOfMonth();
                        }

                        $startDate = $lastOccurrence->copy()->subDays(29)->toDateString();
                        $endDate = $lastOccurrence->toDateString();
                    } else {
                        $startDate = now()->subMonth()->startOfMonth()->toDateString();
                        $endDate = now()->subMonth()->endOfMonth()->toDateString();
                    }
                    break;

                default:
                    $startDate = now()->toDateString();
                    $endDate = now()->toDateString();
            }

            Log::info('Date range calculated', [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);

            // Generate report based on type - FIX THE SWITCH STATEMENT
            $attendances = null;
            $view = null;
            $reportName = null;

            switch ($type) {
                case 'attendance':
                    Log::info('Processing attendance report');
                    $attendances = $reportService->getDaily(
                        $this->organizationId,
                        $ids,
                        $startDate,
                        $endDate,
                        null
                    );
                    $view = 'exports.attendance.daily';
                    $reportName = 'attendance';
                    break;

                case 'timesheets':
                    Log::info('Processing timesheets report');
                    $attendances = $reportService->getMonthly(
                        $this->organizationId,
                        $ids,
                        $startDate,
                        $endDate,
                        null
                    );
                    $view = 'exports.attendance.monthly';
                    $reportName = 'timesheets';
                    break;

                case 'department':
                    Log::info('Processing department report');
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
                    Log::warning('Unknown report type', [
                        'type' => $type,
                        'available_types' => ['attendance', 'timesheets', 'department']
                    ]);
                    return null;
            }

            // Check if we got data
            if (!$attendances || $attendances->isEmpty()) {
                Log::warning('No attendance records found', [
                    'organization_id' => $this->organizationId,
                    'type' => $type,
                    'frequency' => $frequency,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ]);
                return null;
            }

            Log::info('Attendance records found', [
                'count' => $attendances->count(),
            ]);

            $reportTitle = ucfirst($reportName) . ' Report - ' . ucfirst($frequency);

            $result = $reportGenerator->generate(
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

            Log::info('Report generator returned', [
                'result' => $result ? 'success' : 'null',
                'has_path' => isset($result['path']),
            ]);

            return $result;

        } catch (\Throwable $e) {
            Log::error('Report generation exception', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'stack' => $e->getTraceAsString(),
                'type' => $setting->report_type ?? 'unknown',
                'frequency' => $setting->frequency ?? 'unknown',
                'organization_id' => $this->organizationId,
            ]);
            return null;
        }
    }

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
