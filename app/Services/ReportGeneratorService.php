<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ReportGeneratorService
{
    public function generate(string $view, array $data, string $fileName, bool $saveToDisk = false)
    {
        try {
            Log::info('Starting PDF generation', [
                'view' => $view,
                'fileName' => $fileName,
                'saveToDisk' => $saveToDisk,
            ]);

            $pdf = Pdf::loadView($view, $data)
                ->setPaper('a4', 'landscape')
                ->setOptions([
                    'isHtml5ParserEnabled' => true,
                    'isRemoteEnabled' => true,
                    'defaultFont' => 'DejaVu Sans',
                ]);

            if ($saveToDisk) {
                $path = "reports/{$fileName}-" . now()->timestamp . ".pdf";

                Log::info('Saving PDF to disk', ['path' => $path]);

                Storage::disk('public')->put($path, $pdf->output());

                $fullPath = storage_path("app/public/{$path}");

                Log::info('PDF saved successfully', [
                    'path' => $fullPath,
                    'exists' => file_exists($fullPath),
                ]);

                return [
                    'path' => $fullPath,
                    'url' => asset("storage/{$path}"),
                ];
            }

            return $pdf;
        } catch (\Exception $e) {
            Log::error('PDF generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;  // ← This might be what's happening
        }
    }
}
