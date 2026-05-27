<?php

namespace App\Http\Controllers\PDFExports;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\ReportGeneratorService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class ClientsExportController extends Controller
{
    public function exportClientsPdf(Request $request, ReportGeneratorService $generator)
    {
        $ids = $request->input('ids', []);
        $orgId = auth()->user()->employee->organization_id ?? null;

        $organizations = Organization::query()
            ->when($ids, fn($q) => $q->whereIn('id', $ids))
            ->where('id', $orgId)
            ->get();

        if ($organizations->isEmpty()) {
            return redirect()->back()->with('error', 'No clients found to export.');
        }

        $pdf = $generator->generate(
            'exports.clients.index',
            [
                'organizations' => $organizations,
                'title' => 'Clients Report',
                'date' => now()->format('d M Y, H:i'),
            ],
            'clients-report'
        );

        return $pdf->download('clients-report.pdf');
    }


}
