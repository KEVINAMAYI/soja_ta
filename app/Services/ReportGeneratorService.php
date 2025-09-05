<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class ReportGeneratorService
{
    public function generate(string $view, array $data, string $fileName, bool $saveToDisk = false)
    {
        $pdf = Pdf::loadView($view, $data)
            ->setPaper('a4', 'landscape')
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true,
                'defaultFont' => 'DejaVu Sans',
            ]);

        if ($saveToDisk) {
            $path = "reports/{$fileName}-" . now()->timestamp . ".pdf";

            // save to public storage
            Storage::disk('public')->put($path, $pdf->output());

            return [
                'path' => storage_path("app/public/{$path}"), // ✅ local file path for email attachment
                'url'  => asset("storage/{$path}"),           // ✅ public URL for browser access
            ];
        }

        return $pdf;
    }
}
