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
        $ids = $request->input('ids', []);
        $orgId = auth()->user()->employee->organization_id ?? null;

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
                'employees' => $employees,
                'title' => 'Employees Report',
                'date' => now()->format('d M Y, H:i'),
                'isExcel' => false,
            ],
            'employees-report'
        );

        return $pdf->download('employees-report.pdf');
    }
}
