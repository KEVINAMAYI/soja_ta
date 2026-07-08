<?php

namespace App\Exports;

use App\Models\Employee;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Maatwebsite\Excel\Concerns\WithEvents;


class EmployeesExcelExport implements FromView, ShouldAutoSize, WithTitle, WithStyles, WithEvents
{
    protected array $selectedIds;
    protected string $empTypeFilter;
    protected string $activeFilter;

    public function __construct(
        array $selectedIds = [],
        string $empTypeFilter = '',
        string $activeFilter = ''
    ) {
        $this->selectedIds   = $selectedIds;
        $this->empTypeFilter = $empTypeFilter;
        $this->activeFilter  = $activeFilter;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastCol = $sheet->getHighestColumn();
                $lastRow = $sheet->getHighestRow();

                // Apply autofilter on header row
                $sheet->setAutoFilter("A1:{$lastCol}1");

                // Freeze the header row so it stays visible when scrolling
                $sheet->freezePane('A2');
            },
        ];
    }

    public function view(): View
    {
        $user           = auth()->user();
        $organizationId = $user->employee->organization_id;
        $org            = $user->employee->organization;

        $query = Employee::query()
            ->with(['organization', 'shift', 'user', 'department', 'zkbioAreas'])
            ->where('organization_id', $organizationId);

        if (!empty($this->selectedIds)) {
            $query->whereIn('id', $this->selectedIds);
        }

        if ($this->empTypeFilter !== '') {
            $query->where('employee_type', $this->empTypeFilter);
        }

        if ($this->activeFilter !== '') {
            $query->where('active', (int) $this->activeFilter);
        }

        $employees = $query->get();

        return view('exports.employees.index', [
            'employees'    => $employees,
            'orgName'      => $org->name ?? 'Organization',
            'date'         => now()->format('d M Y, H:i'),
            'totalActive'  => $employees->where('active', 1)->count(),
            'totalInactive'=> $employees->where('active', 0)->count(),
            'total'        => $employees->count(),
            'isExcel'      => true,
        ]);
    }

    public function title(): string
    {
        return 'Employee Report';
    }

    public function styles(Worksheet $sheet)
    {
        $lastCol = $sheet->getHighestColumn();
        $lastRow = $sheet->getHighestRow();

        // Style header row
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['argb' => 'FFFFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF0f172a'],
            ],
        ]);

        // All rows alignment
        $sheet->getStyle("A1:{$lastCol}{$lastRow}")
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT)
            ->setVertical(Alignment::VERTICAL_CENTER);

        $sheet->getDefaultRowDimension()->setRowHeight(18);
        $sheet->getRowDimension(1)->setRowHeight(24);
    }
}
