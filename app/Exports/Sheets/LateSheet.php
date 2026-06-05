<?php

namespace App\Exports\Sheets;

use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;

class LateSheet implements FromArray, WithTitle, WithStyles, WithColumnWidths
{
    use TaSheetHelpers;

    public function __construct(
        private readonly array   $records,
        private readonly ?string $startDate,
        private readonly ?string $endDate,
    ) {}

    public function title(): string { return 'Late Report'; }

    public function columnWidths(): array
    {
        return ['A' => 12, 'B' => 14, 'C' => 14, 'D' => 22, 'E' => 16, 'F' => 14, 'G' => 11, 'H' => 11, 'I' => 13, 'J' => 13];
    }

    public function array(): array
    {
        $out   = [];
        $out[] = ['LATENESS REPORT', ...array_fill(0, 9, '')];
        $out[] = [$this->periodLabel($this->startDate, $this->endDate), ...array_fill(0, 9, '')];
        $out[] = [
            'Date', 'Employee Type', 'Employee Title', 'Employee Number', 'Name', 'Department', 'Section',
            "Actual\nTime In", "Actual\nTime Out", "Expected Working\nHours", "Lateness\nTotal Time",
        ];
        foreach ($this->records as $r) {
            $emp   = $r->employee ?? null;
            $shift = $emp?->shift;
            $out[] = [
                $this->fmtDate($r->date),
                $emp?->employee_type ?? '',
                $emp?->employee_title ?? '',
                $emp?->ad_employee_id ?? $emp?->id ?? '',
                $emp?->name ?? '',
                $emp?->department?->name ?? '',
                $emp?->section ?? '',
                $this->fmtTime($r->check_in_time),
                $this->fmtTime($r->check_out_time),
                $shift ? $this->definedHours($shift) : '',
                $r->minutes_late > 0 ? $this->minutesToHHMM($r->minutes_late) : '',
            ];
        }
        $ds    = 4;
        $de    = 3 + count($this->records);
        $out[] = ["TOTAL LATE ARRIVALS: " . count($this->records), ...array_fill(0, 8, ''), "=COUNTA(J{$ds}:J{$de})"];
        return $out;
    }

    public function styles(Worksheet $sheet): array
    {
        $last  = 3 + count($this->records);
        $total = $last + 1;

        $sheet->mergeCells('A1:J1');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'name' => 'Arial', 'color' => ['rgb' => '856404']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF8E1']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(22);

        $sheet->mergeCells('A2:J2');
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['name' => 'Arial', 'size' => 8, 'color' => ['rgb' => '555555']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(15);

        $sheet->getRowDimension(3)->setRowHeight(36);
        $sheet->getStyle('A3:J3')->applyFromArray($this->hdrStyle('856404'));

        for ($row = 4; $row <= $last; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(18);
            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:J{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFFDE7');
            }
            $sheet->getStyle("A{$row}:J{$row}")->applyFromArray($this->dataStyle('center'));
            $sheet->getStyle("D{$row}:F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle("J{$row}")->getFont()->setBold(true)->getColor()->setRGB('856404');

            $empType = $sheet->getCell("B{$row}")->getValue();
            if ($empType) {
                [$bg, $fg] = match ($empType) {
                    'COSMOS'     => ['E0F2FE', '0369A1'],
                    'Outsourced' => ['FDE8E3', 'C0341B'],
                    default      => ['F1F5F9', '475569'],
                };
                $sheet->getStyle("B{$row}")->applyFromArray($this->badgeStyle($bg, $fg));
            }
        }

        $sheet->mergeCells("A{$total}:I{$total}");
        $sheet->getStyle("A{$total}:J{$total}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 9, 'name' => 'Arial', 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '856404']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle("J{$total}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF3CD');
        $sheet->getStyle("J{$total}")->getFont()->setColor(new Color('000000'))->setBold(true);
        $sheet->getRowDimension($total)->setRowHeight(18);

        $sheet->freezePane('A4');
        $sheet->setAutoFilter('A3:J3');
        return [];
    }

    private function definedHours($shift): string
    {
        $mins = Carbon::parse($shift->start_time)->diffInMinutes(Carbon::parse($shift->end_time));
        return number_format($mins / 60, 1);
    }

    private function minutesToHHMM(int $minutes): string
    {
        $h = floor($minutes / 60);
        $m = $minutes % 60;
        return sprintf('%d:%02d', $h, $m);
    }
}
