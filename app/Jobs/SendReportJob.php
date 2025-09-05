<?php

namespace App\Jobs;

use App\Mail\ReportMail;
use App\Models\ReportSetting;
use App\Services\ReportGeneratorService;
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

            $reportFile = $this->generateReport($setting->report_type);

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

            Mail::to($setting->email)->send(new ReportMail($setting, $reportFile));

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

    private function generateReport(string $type, array $ids = []): ?string
    {
        try {

            $reportService = app(AttendanceReportService::class);
            $reportGenerator = app(ReportGeneratorService::class);

            switch ($type) {
                case 'daily_attendance':
                    $attendances = $reportService->getDaily($this->organizationId, $ids);
                    $view = 'exports.attendance.daily';
                    break;

                case 'monthly_attendance':
                    $lastMonth = now()->subMonth()->format('Y-m'); // always last month
                    $attendances = $reportService->getMonthly($this->organizationId, $ids, $lastMonth);
                    $view = 'exports.attendance.monthly';
                    break;

                case 'daily_department_attendance':
                    $filters = [
                        'date' => $specificDate ?? now()->toDateString()
                    ];
                    $attendances = $reportService->getByDepartment($this->organizationId, $ids, $filters);
                    $view = 'exports.attendance.department';
                    break;

                case 'weekly_department_attendance':
                    $weekStart = $startOfWeek ?? now()->startOfWeek()->toDateString();
                    $weekEnd = $endOfWeek ?? now()->endOfWeek()->toDateString();
                    $filters = [
                        'week_start' => $weekStart,
                        'week_end' => $weekEnd
                    ];
                    $attendances = $reportService->getByDepartment($this->organizationId, $ids, $filters);
                    $view = 'exports.attendance.department';
                    break;

                case 'monthly_department_attendance':
                    $month = now()->subMonth()->format('Y-m') ?? now()->format('Y-m');
                    $filters = [
                        'month' => $month
                    ];
                    $attendances = $reportService->getByDepartment($this->organizationId, $ids, $filters);
                    $view = 'exports.attendance.department';
                    break;

                default:
                    Log::warning('Unknown report type', ['type' => $type]);
                    return null;
            }

            if ($attendances->isEmpty()) {
                Log::warning('No attendance records found', [
                    'organization_id' => $this->organizationId,
                    'type' => $type,
                ]);
                return null;
            }

            return $reportGenerator->generate(
                $view,
                [
                    'title' => 'Attendance Report',
                    'date' => now()->format('d M Y, H:i'),
                    'isExcel' => false,
                    'attendances' => $attendances,
                ],
                "{$type}-report",
                saveToDisk: true
            );

        } catch (\Throwable $e) {
            Log::error('Report generation failed', [
                'error' => $e->getMessage(),
                'stack' => $e->getTraceAsString(),
                'type' => $type,
                'organization_id' => $this->organizationId,
            ]);
            return null;
        }
    }
}
