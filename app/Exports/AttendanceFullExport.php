<?php

namespace App\Exports;

use App\Exports\Sheets\MasterSheet;
use App\Exports\Sheets\PresentSheet;
use App\Exports\Sheets\LateSheet;
use App\Exports\Sheets\AbsentSheet;
use App\Services\AttendanceReportService;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * AttendanceFullExport
 *
 * Produces 4-sheet T&A Excel report:
 *   Sheet 1 — Master        (all records + lost hours + interpretation)
 *   Sheet 2 — Present       (clocked in / out only)
 *   Sheet 3 — Late Report   (is_late_checkin=1, within_grace_period=0)
 *   Sheet 4 — Absent Report (absent / leave / off_shift)
 *
 * Usage:
 *   Excel::download(new AttendanceFullExport(...), 'T_A_Report.xlsx');
 */
class AttendanceFullExport implements WithMultipleSheets
{
    public function __construct(
        public readonly int     $orgId,
        public readonly array   $ids        = [],
        public readonly ?string $startDate  = null,
        public readonly ?string $endDate    = null,
        public readonly ?int    $departmentId = null,
    ) {}

    public function sheets(): array
    {
        /** @var AttendanceReportService $svc */
        $svc = app(AttendanceReportService::class);

        $args = [
            $this->orgId,
            $this->ids,
            $this->startDate,
            $this->endDate,
            $this->departmentId,
        ];

        return [
            new MasterSheet($svc->getMaster(...$args),   $this->startDate, $this->endDate),
            new PresentSheet($svc->getPresent(...$args), $this->startDate, $this->endDate),
            new LateSheet($svc->getLateness(...$args),   $this->startDate, $this->endDate),
            new AbsentSheet($svc->getAbsent(...$args),   $this->startDate, $this->endDate),
        ];
    }
}
