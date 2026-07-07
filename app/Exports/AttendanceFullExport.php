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
        public readonly ?string $employeeType = null,

    ) {}

    public function sheets(): array
    {
        ini_set('memory_limit', '512M');

        /** @var AttendanceReportService $svc */
        $svc = app(AttendanceReportService::class);
        $args = [
            $this->orgId,
            $this->ids,
            $this->startDate,
            $this->endDate,
            $this->departmentId,
            $this->employeeType,
        ];

        // Load master records once — derive other sheets from it
        $master  = $svc->getMaster(...$args);
        $present = array_values(array_filter($master, fn($r) => in_array($r->status ?? '', ['clocked_in', 'clocked_out'])));
        $late    = array_values(array_filter($master, fn($r) => ($r->is_late_checkin ?? false) && !($r->within_grace_period ?? false)));
        $absent  = array_values(array_filter($master, fn($r) => in_array($r->status ?? '', ['absent', 'unchecked_in', 'on_leave', 'sick_leave', 'sick_off'])));

        return [
            new MasterSheet($master,  $this->startDate, $this->endDate),
            new PresentSheet($present, $this->startDate, $this->endDate),
            new LateSheet($late,      $this->startDate, $this->endDate),
            new AbsentSheet($absent,  $this->startDate, $this->endDate),
        ];
    }
}
