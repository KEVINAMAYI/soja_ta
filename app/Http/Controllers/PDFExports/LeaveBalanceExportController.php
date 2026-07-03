<?php

namespace App\Http\Controllers\PDFExports;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Services\LeaveApprovalService;
use App\Services\ReportGeneratorService;
use Illuminate\Http\Request;

class LeaveBalanceExportController extends Controller
{
    protected ReportGeneratorService $reportGenerator;

    public function __construct(ReportGeneratorService $reportGenerator)
    {
        $this->reportGenerator = $reportGenerator;
    }

    public function exportPdf(Request $request)
    {
        $orgId = auth()->user()->employee->organization_id ?? null;
        $year = (int) $request->input('year', now()->year);
        $leaveTypeId = $request->input('leave_type_id');
        $departmentId = $request->input('department_id');
        $search = $request->input('search');

        $employees = Employee::where('organization_id', $orgId)
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->when($search, fn ($q) => $q->where('name', 'like', '%' . $search . '%'))
            ->with('department')
            ->orderBy('name')
            ->get();

        $service = app(LeaveApprovalService::class);

        if ($leaveTypeId) {
            $type = LeaveType::find($leaveTypeId);
            $rows = $type ? $service->balancesForType($type, $employees, $year) : [];
        } else {
            $types = LeaveType::where('organization_id', $orgId)->where('is_active', true)->orderBy('name')->get();
            $rows = $service->balancesForTypes($types, $employees, $year);
        }

        $pdf = $this->reportGenerator->generate(
            'exports.leave-balances.index',
            [
                'data' => [
                    'rows' => $rows,
                    'title' => 'Leave Balances Report',
                    'orgName' => auth()->user()->employee->organization->name ?? 'Organization',
                    'year' => $year,
                    'date' => now()->format('d M Y, H:i'),
                ],
            ],
            'leave-balances-report'
        );

        return $pdf->download('leave-balances-report.pdf');
    }
}
