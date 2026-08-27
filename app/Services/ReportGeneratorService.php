<?php

namespace App\Services;

use App\Exports\GenericViewExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ReportGeneratorService
{
    public function generate(string $view, array $data, string $fileName, bool $saveToDisk = false, string $format = 'pdf')
    {
        if ($format === 'excel') {
            return $this->generateExcel($view, $data, $fileName, $saveToDisk);
        }

        $pdf = Pdf::loadView($view, $data)
            ->setPaper('a4', 'landscape')
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled'      => true,
                'defaultFont'          => 'DejaVu Sans',
            ]);

        if ($saveToDisk) {
            $path     = "reports/{$fileName}-" . now()->timestamp . ".pdf";
            Storage::disk('public')->put($path, $pdf->output());
            return [
                'path' => storage_path("app/public/{$path}"),
                'url'  => asset("storage/{$path}"),
            ];
        }

        return $pdf;
    }

    protected function generateExcel(string $view, array $data, string $fileName, bool $saveToDisk)
    {
        $export = new GenericViewExport($view, array_merge($data, ['isExcel' => true]));
        $path = "reports/{$fileName}-" . now()->timestamp . ".xlsx";

        if ($saveToDisk) {
            Excel::store($export, $path, 'public');
            return [
                'path' => storage_path("app/public/{$path}"),
                'url'  => asset("storage/{$path}"),
            ];
        }

        return Excel::download($export, basename($path));
    }
}
