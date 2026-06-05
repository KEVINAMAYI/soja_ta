<?php

namespace App\Exports\Sheets;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;

trait TaSheetHelpers
{
    private function hdrStyle(string $hexFill): array
    {
        return [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9, 'name' => 'Arial'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $hexFill]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
        ];
    }

    private function dataStyle(string $align = 'center', bool $bold = false): array
    {
        return [
            'font' => ['name' => 'Arial', 'size' => 9, 'bold' => $bold],
            'alignment' => ['horizontal' => $align, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
        ];
    }

    private function badgeStyle(string $bgHex, string $fgHex): array
    {
        return [
            'font' => ['name' => 'Arial', 'size' => 9, 'bold' => true, 'color' => ['rgb' => $fgHex]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bgHex]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
        ];
    }

    private function interpretationBadge(string $interp): array
    {
        return match ($interp) {
            'Attendance OK'                      => ['D4EDDA', '155724'],
            'Late In'                            => ['FFF3CD', '856404'],
            'Late In & Late Out'                 => ['F8D7DA', '721C24'],
            'Early Out'                          => ['FFF3CD', '856404'],
            'Extended Lunch'                     => ['D1ECF1', '0C5460'],
            'Absent'                             => ['F8D7DA', '721C24'],
            'Absent with Approved Leave'         => ['CCE5FF', '004085'],
            'Absent with Approved Gate Pass'     => ['E2D9F3', '432E75'],
            default                              => ['F8D7DA', '721C24'],
        };
    }

    private function fmtTime(?string $t): string
    {
        if (!$t) return '';
        try { return Carbon::parse($t)->format('H:i'); } catch (\Throwable) { return ''; }
    }

    private function fmtDate(?string $d): string
    {
        if (!$d) return '';
        try { return Carbon::parse($d)->format('d/m/Y'); } catch (\Throwable) { return ''; }
    }

    private function fmtHours(float $h): string
    {
        return $h > 0 ? number_format($h, 1) : '0.0';
    }

    private function periodLabel(?string $start, ?string $end): string
    {
        $s = $start ? Carbon::parse($start)->format('d M Y') : '—';
        $e = $end   ? Carbon::parse($end)->format('d M Y')   : $s;
        return "Period: {$s} – {$e}  |  Generated: " . now()->format('d M Y H:i');
    }

    private function applyBorder(Worksheet $ws, string $range): void
    {
        $ws->getStyle($range)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)
            ->setColor(new Color('CCCCCC'));
    }
}
