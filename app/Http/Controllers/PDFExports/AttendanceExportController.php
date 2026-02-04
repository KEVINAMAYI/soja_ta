<?php

namespace App\Http\Controllers\PDFExports;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\AttendanceReportService;
use App\Services\ReportGeneratorService;

class AttendanceExportController extends Controller
{
    protected AttendanceReportService $reportService;
    protected ReportGeneratorService $reportGenerator;

    public function __construct(AttendanceReportService $reportService, ReportGeneratorService $reportGenerator)
    {
        $this->reportService = $reportService;
        $this->reportGenerator = $reportGenerator;
    }

    public function exportAttendanceDailyPdf(Request $request)
    {
        $ids = $request->input('ids', []);
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $status = $request->input('status');

        $orgId = auth()->user()->employee->organization_id ?? null;

        $attendances = $this->reportService->getDaily(
            orgId: $orgId,
            ids: $ids,
            startDate: $startDate,
            endDate: $endDate,
            status: $status,
        );

        if ($attendances->isEmpty()) {
            return back()->with('error', 'No attendance records found to export.');
        }

        // build PDF
        $pdf = $this->reportGenerator->generate(
            'exports.attendance.daily',
            [
                'attendances' => $attendances,
                'title' => 'Attendance Report',
                'date' => now()->format('d M Y, H:i'),
                'isExcel' => false,
            ],
            'attendance-report',
        );

        return $pdf->download('daily-attendance-report.pdf');
    }

    public function exportAttendanceMonthlyPdf(Request $request)
    {
        $ids = $request->input('ids', []);
        $start_date = $request->input('start_date');
        $end_date = $request->input('end_date');
        $department_id = $request->input('department_id');
        $orgId = auth()->user()->employee->organization_id ?? null;

        $attendances = $this->reportService->getMonthly($orgId, $ids, $start_date, $end_date, $department_id);

        if ($attendances->isEmpty()) {
            return back()->with('error', 'No attendance records found to export.');
        }

        $pdf = $this->reportGenerator->generate(
            'exports.attendance.monthly',
            [
                'attendances' => $attendances,
                'title' => 'Attendance Report',
                'date' => now()->format('d M Y, H:i'),
                'isExcel' => false,
                'startDate' => $start_date,
                'endDate' => $end_date,
            ],
            'attendance-report',
        );

        return $pdf->download('timesheets-report.pdf');
    }

    public function exportAttendanceDepartmentPdf(Request $request)
    {
        $ids = $request->input('ids', []);
        $start_date = $request->input('start_date');
        $end_date = $request->input('end_date');

        $orgId = auth()->user()->employee->organization_id ?? null;

        $attendances = $this->reportService->getByDepartment($orgId, $ids, $start_date, $end_date);

        if ($attendances->isEmpty()) {
            return back()->with('error', 'No attendance records found to export.');
        }

        $pdf = $this->reportGenerator->generate(
            'exports.attendance.department',
            [
                'attendances' => $attendances,
                'title' => 'Attendance Report',
                'date' => now()->format('d M Y, H:i'),
                'isExcel' => false,
                'startDate' => $start_date,
                'endDate' => $end_date,
            ],
            'attendance-report',
        );

        return $pdf->download('department-attendance-report.pdf');
    }
}
