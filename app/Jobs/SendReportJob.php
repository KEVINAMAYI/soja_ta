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
use App\Exports\AttendanceFullExport;

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

        Log::info('🔥 JOB ENTERED HANDLE', [
            'setting_id' => $this->settingId,
            'organization_id' => $this->organizationId,
        ]);

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

            \Log::info('🚨 SendReportJob DEBUG @ line 66', [
                'settingId' => $this->settingId ?? 'not set',
                'organizationId' => $this->organizationId ?? 'not set',

                // dump every variable used near line 66
                'reportFile' => $reportFile ?? 'UNDEFINED',
                'reportFile_type' => isset($reportFile) ? gettype($reportFile) : 'UNSET',

                'this_reportFile' => $this->reportFile ?? 'UNDEFINED',
                'this_reportFile_type' => isset($this->reportFile) ? gettype($this->reportFile) : 'UNSET',
            ]);


            // FIX: Check if report generation failed and exit early
            if (!$reportFile || !isset($reportFile['path'])) {
                Log::info('Report generation failed - skipping email', [
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

            Log::info('📧 ABOUT TO SEND EMAIL', [
                'email' => $setting->email,
                'path' => $reportFile['path'],
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
            Log::info('Report sending failed', [
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

            // Full T&A is a multi-sheet workbook (Master/Present/Late/Absent), not a
            // single Blade view rendered over a flat $attendances collection like the
            // other types below, so it's generated via its own export class.
            if ($type === 'full_ta') {
                return $this->generateFullTaReport($reportService, $reportGenerator, $startDate, $endDate, $frequency);
            }

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
                        'available_types' => ['attendance', 'timesheets', 'department', 'full_ta']
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

            // Guard against reports that only contain non-meaningful statuses (e.g. no_scheduled)
            $meaningfulStatuses = ['clocked_in', 'clocked_out', 'on_break','absent', 'unchecked_in', 'on_leave', 'sick_leave', 'sick_off', 'off_shift'];
            $hasMeaningfulData = match($type) {
                'attendance' => $attendances->contains(fn($r) => in_array($r->status, $meaningfulStatuses)),
                'timesheets', 'department' => $attendances->isNotEmpty(),
                default => false,
            };

            if (!$hasMeaningfulData) {
                Log::info('Report skipped - no meaningful attendance data', [
                    'type' => $type,
                    'organization_id' => $this->organizationId,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'record_count' => $attendances->count(),
                ]);
                return null;
            }

            // ↓ Only reaches here if data is meaningful
            Log::info('Meaningful Attendance records found', [
                'count' => $attendances->count(),
            ]);

            $reportTitle = ucfirst($reportName) . ' Report - ' . ucfirst($frequency);
            $format = $setting->format ?? 'pdf';

            $result = $reportGenerator->generate(
                $view,
                [
                    'title' => $reportTitle,
                    'date' => now()->format('d M Y, H:i'),
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                    'isExcel' => $format === 'excel',
                    'attendances' => $attendances,
                ],
                "{$reportName}-{$frequency}-report-" . now()->format('Y-m-d'),
                saveToDisk: true,
                format: $format
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

    private function generateFullTaReport(
        AttendanceReportService $reportService,
        ReportGeneratorService $reportGenerator,
        string $startDate,
        string $endDate,
        string $frequency
    ): ?array {
        $master = $reportService->getMaster($this->organizationId, [], $startDate, $endDate);

        if (empty($master)) {
            Log::info('Full T&A report skipped - no records found', [
                'organization_id' => $this->organizationId,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);
            return null;
        }

        Log::info('Generating Full T&A report', [
            'organization_id' => $this->organizationId,
            'record_count' => count($master),
        ]);

        $export = new AttendanceFullExport(
            orgId: $this->organizationId,
            startDate: $startDate,
            endDate: $endDate,
        );

        return $reportGenerator->generateFromExport(
            $export,
            "full-ta-{$frequency}-report-" . now()->format('Y-m-d')
        );
    }

    private function calculateNextRun(ReportSetting $setting, Carbon $from): ?Carbon
    {
        // Delegates to ReportSetting::calculateNextRunFrom() — was previously
        // duplicated here with a bug: setTimeFromTimeString($setting->time) was
        // passed the cast Carbon *object*, not a "H:i" string, so PHP's implicit
        // __toString() coercion fed it something like "2025-01-01 09:00:00"
        // instead of "09:00", producing a garbage next_run_at. Harmless while
        // next_run_at was purely informational, but SendReportsCommand now relies
        // on it to survive a missed/delayed cron tick — see shouldRun().
        return $setting->calculateNextRunFrom($from);
    }
}
