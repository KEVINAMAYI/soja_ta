<?php

namespace App\Exports;

use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class LeaveBalancesExcelExport implements FromView, ShouldAutoSize, WithTitle, WithStyles
{
    public function __construct(protected array $rows, protected string $orgName, protected int $year)
    {
    }

    public function view(): View
    {
        return view('exports.leave-balances.index', [
            'data' => [
                'rows' => $this->rows,
                'title' => 'Leave Balances Report',
                'orgName' => $this->orgName,
                'year' => $this->year,
                'date' => now()->format('d M Y, H:i'),
            ],
        ]);
    }

    public function title(): string
    {
        return 'Leave Balances';
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:G1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0f172a']],
        ]);

        $sheet->getDefaultRowDimension()->setRowHeight(18);
    }
}
