<?php

namespace App\Http\Controllers\PDFExports;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\Request;
use App\Services\ReportGeneratorService;

class EmployeeExportController extends Controller
{
    protected ReportGeneratorService $reportGenerator;

    public function __construct(ReportGeneratorService $reportGenerator)
    {
        $this->reportGenerator = $reportGenerator;
    }

    public function exportEmployeePdf(Request $request)
    {
        $ids   = $request->input('ids', []);
        $orgId = auth()->user()->employee->organization_id ?? null;
        $org   = auth()->user()->employee->organization;

        $query = Employee::query()
            ->with(['organization', 'shift', 'user', 'department'])
            ->where('organization_id', $orgId);

        if (!empty($ids)) {
            $query->whereIn('id', $ids);
        }

        $employees = $query->get();

        if ($employees->isEmpty()) {
            return redirect()->back()->with('error', 'No employees found to export.');
        }

        $pdf = $this->reportGenerator->generate(
            'exports.employees.index',
            [
                'employees'     => $employees,
                'orgName'       => $org->name ?? 'Organization',
                'date'          => now()->format('d M Y, H:i'),
                'total'         => $employees->count(),
                'totalActive'   => $employees->where('active', 1)->count(),
                'totalInactive' => $employees->where('active', 0)->count(),
            ],
            'employees-report'
        );

        if (!$pdf) {
            return redirect()->back()->with('error', 'Failed to generate PDF. Check logs.');
        }

        return $pdf->download('employees-report.pdf');
    }
}
