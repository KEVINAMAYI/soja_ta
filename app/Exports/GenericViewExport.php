<?php

namespace App\Exports;

use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

/**
 * Renders the same Blade view already used for a report's PDF output as an
 * Excel sheet, so scheduled reports can offer Excel without a dedicated
 * per-report-type export class.
 */
class GenericViewExport implements FromView, ShouldAutoSize
{
    public function __construct(protected string $viewName, protected array $data)
    {
    }

    public function view(): View
    {
        return view($this->viewName, $this->data);
    }
}
