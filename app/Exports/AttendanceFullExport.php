<?php

namespace App\Exports;

use App\Exports\Sheets\MasterSheet;
use App\Exports\Sheets\PresentSheet;
use App\Exports\Sheets\LateSheet;
use App\Exports\Sheets\AbsentSheet;
use App\Services\AttendanceReportService;
use App\Services\InterpretationService;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Single entry-point export that produces all four T&A sheets
 * matching the client's template exactly:
 *
 *   Sheet 1 – Master          (all records + Interpretation column)
 *   Sheet 2 – Present         (clocked in / out)
 *   Sheet 3 – Late Report     (is_late_checkin = 1, within_grace_period = 0)
 *   Sheet 4 – Absent Report   (absent / leave / gate-pass)
 *
 * Usage:
 *   Excel::download(new AttendanceFullExport(...), 'T_A_Report.xlsx');
 */
class AttendanceFullExport implements WithMultipleSheets
{
    public function __construct(
        public readonly int     $orgId,
        public readonly array   $ids = [],
        public readonly ?string $startDate = null,
        public readonly ?string $endDate = null,
        public readonly ?int    $departmentId = null,
    )
    {
    }

    public function sheets(): array
    {
        $svc = app(AttendanceReportService::class);
        $interp = app(InterpretationService::class);

        $params = [
            'orgId' => $this->orgId,
            'ids' => $this->ids,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'departmentId' => $this->departmentId,
        ];

        return [
            new MasterSheet($svc->getMaster(...array_values($params)), $this->startDate, $this->endDate),
            new PresentSheet($svc->getPresent(...array_values($params)), $this->startDate, $this->endDate),
            new LateSheet($svc->getLateness(...array_values($params)), $this->startDate, $this->endDate),
            new AbsentSheet($svc->getAbsent(...array_values($params)), $this->startDate, $this->endDate),
        ];
    }
}
