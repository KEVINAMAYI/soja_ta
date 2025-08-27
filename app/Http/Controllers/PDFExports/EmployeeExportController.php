<?php

namespace App\Http\Controllers\PDFExports;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class EmployeeExportController extends Controller
{
    public function exportPdf(Request $request)
    {
        $ids = $request->input('ids', []);
        $orgId = auth()->user()->employee->organization_id ?? null;

        $query = Employee::query()
            ->select('employees.*')
            ->with(['organization', 'shift', 'user', 'department'])
            ->where('organization_id', $orgId);


        if (!empty($ids)) {
            $query->whereIn('id', $ids);
        }

        $employees = $query->get();

        if ($employees->isEmpty()) {
            return redirect()->back()->with('error', 'No employees found to export.');
        }

        $pdf = Pdf::loadView('exports.employees.index', ['employees' => $employees])
            ->setPaper('a4', 'landscape');

        $pdf->setOptions([
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => true,
            'defaultFont' => 'DejaVu Sans'
        ]);

        return $pdf->download('employees-report.pdf');
    }
}
