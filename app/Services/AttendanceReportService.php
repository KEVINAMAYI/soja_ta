<?php

namespace App\Services;

use App\Models\Attendance;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Extends the existing AttendanceReportService with the new report types
 * requested by the client:
 *
 *  - getWeeklyHours()       Weekly hours worked per employee (and by dept)
 *  - getPresent()           Present-only report (with interpretation)
 *  - getAbsent()            Absent-only report  (with interpretation)
 *  - getLateness()          Late-arrivals report
 *  - getMaster()            Master file with Interpretation column
 *  - getWeeklyByDepartment()
 */
class AttendanceReportService
{
    // =========================================================================
    // EXISTING METHODS (unchanged — kept here for reference)
    // =========================================================================

    public function getDaily(
        int     $orgId,
        array   $ids = [],
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $status = null,
    ) {
        $startDate = $startDate ?? now()->toDateString();
        $endDate   = $endDate   ?? $startDate;

        $startDate = Carbon::parse($startDate)->toDateString();
        $endDate   = Carbon::parse($endDate)->toDateString();

        $query = Attendance::with(['employee.shift'])
            ->whereHas('employee', fn($q) => $q->where('organization_id', $orgId))
            ->whereBetween('date', [$startDate, $endDate]);

        if (!empty($ids)) {
            $query->whereIn('id', $ids);
        }

        if ($status) {
            if ($status === 'absent') {
                $query->whereIn('status', ['absent', 'unchecked_in']);
            } elseif ($status === 'present') {
                $query->whereIn('status', ['clocked_in', 'clocked_out']);
            } else {
                $query->where('status', $status);
            }
        }

        $query->orderBy('date')->orderBy('check_in_time');
        $records = $query->get();

        if ($status) {
            return $records->unique(fn($item) => $item->employee_id . '_' . $item->date)->values();
        }

        return $records;
    }

    public function baseQuery($orgId, $ids, $start_date, $end_date, $department_id)
    {
        $query = Attendance::query()
            ->join('employees', 'attendances.employee_id', '=', 'employees.id')
            ->where('employees.organization_id', $orgId);

        if ($department_id && $department_id !== 'all') {
            $query->where('employees.department_id', $department_id);
        }

        if ($start_date) $query->where('attendances.date', '>=', $start_date);
        if ($end_date)   $query->where('attendances.date', '<=', $end_date);
        if (!empty($ids)) $query->whereIn('attendances.employee_id', $ids);

        return $query;
    }

    public function getMonthly(int $orgId, array $ids, string $start_date, string $end_date, $department_id = null)
    {
        $query = $this->baseQuery($orgId, $ids, $start_date, $end_date, $department_id)
            ->with(['employee', 'employee.department', 'employee.shift']);

        $query->select(
            'attendances.employee_id',
            DB::raw("COUNT(DISTINCT attendances.date) as total_days"),
            DB::raw("COUNT(DISTINCT CASE WHEN attendances.status IN ('clocked_in','clocked_out') OR attendances.check_in_time IS NOT NULL THEN attendances.date END) as present_days"),
            DB::raw("COUNT(DISTINCT CASE WHEN attendances.status IN ('absent','unchecked_in') THEN attendances.date END) as absent_days"),
            DB::raw("COUNT(DISTINCT CASE WHEN attendances.status = 'on_leave' THEN attendances.date END) as leave_days"),
            DB::raw("COUNT(DISTINCT CASE WHEN attendances.status IN ('sick_leave','sick_off') THEN attendances.date END) as sick_days"),
            DB::raw("COUNT(DISTINCT CASE WHEN attendances.status = 'off_shift' THEN attendances.date END) as off_shift_days"),
            DB::raw("SUM(attendances.worked_hours) as total_worked_hours"),
            DB::raw("SUM(attendances.overtime_hours) as total_ot_hours")
        )->groupBy('attendances.employee_id');

        return $query->get()->map(fn($record) => (object)[
            'employee_id'        => $record->employee_id,
            'present_days'       => $record->present_days,
            'absent_days'        => $record->absent_days,
            'leave_days'         => $record->leave_days,
            'sick_days'          => $record->sick_days,
            'off_shift_days'     => $record->off_shift_days,
            'total_days'         => $record->total_days,
            'total_worked_hours' => $record->total_worked_hours,
            'total_ot_hours'     => $record->total_ot_hours,
            'employee'           => $record->employee,
        ])->values();
    }

    public function getByDepartment($orgId, $ids, $start_date, $end_date)
    {
        $orgId = auth()->user()->employee->organization_id ?? $orgId;
        $ids   = $ids ?? [];

        $query = Attendance::query()
            ->join('employees',   'attendances.employee_id',  '=', 'employees.id')
            ->join('departments', 'employees.department_id',  '=', 'departments.id')
            ->where('employees.organization_id', $orgId);

        if ($start_date) $query->where('attendances.date', '>=', $start_date);
        if ($end_date)   $query->where('attendances.date', '<=', $end_date);
        if (!empty($ids)) $query->whereIn('employees.id', $ids);

        $query->select(
            'employees.department_id',
            'departments.name as department_name',
            DB::raw("COUNT(DISTINCT employees.id) as employee_count"),
            DB::raw("COUNT(DISTINCT CONCAT(employees.id, '-', attendances.date)) as total_days"),
            DB::raw("COUNT(DISTINCT CASE WHEN attendances.status IN ('clocked_in','clocked_out') THEN CONCAT(employees.id, '-', attendances.date) END) as present_days"),
            DB::raw("COUNT(DISTINCT CASE WHEN attendances.status IN ('absent','unchecked_in') THEN CONCAT(employees.id, '-', attendances.date) END) as absent_days"),
            DB::raw("COUNT(DISTINCT CASE WHEN attendances.status = 'on_leave' THEN CONCAT(employees.id, '-', attendances.date) END) as leave_days"),
            DB::raw("COUNT(DISTINCT CASE WHEN attendances.status IN ('sick_leave','sick_off') THEN CONCAT(employees.id, '-', attendances.date) END) as sick_days"),
            DB::raw("COUNT(DISTINCT CASE WHEN attendances.status = 'off_shift' THEN CONCAT(employees.id, '-', attendances.date) END) as off_shift_days"),
            DB::raw("SUM(attendances.worked_hours) as total_worked_hours"),
            DB::raw("SUM(attendances.overtime_hours) as total_ot_hours")
        )->groupBy('employees.department_id', 'departments.name');

        return $query->get()->map(fn($record) => (object)[
            'department_id'      => $record->department_id,
            'department_name'    => $record->department_name,
            'employee_count'     => $record->employee_count,
            'present_days'       => $record->present_days,
            'absent_days'        => $record->absent_days,
            'leave_days'         => $record->leave_days,
            'sick_days'          => $record->sick_days,
            'off_shift_days'     => $record->off_shift_days,
            'total_days'         => $record->total_days,
            'total_worked_hours' => $record->total_worked_hours,
            'total_ot_hours'     => $record->total_ot_hours,
        ])->values();
    }

    // =========================================================================
    // NEW METHODS
    // =========================================================================

    /**
     * MASTER FILE — every record in range, decorated with Interpretation column.
     * Loads with employee + shift so the view can render shift info.
     */
    public function getMaster(
        int     $orgId,
        array   $ids = [],
        ?string $startDate = null,
        ?string $endDate = null,
        ?int    $departmentId = null,
        ?string $employeeType = null,
    ) {
        $startDate = Carbon::parse($startDate ?? now()->toDateString())->toDateString();
        $endDate   = Carbon::parse($endDate   ?? $startDate)->toDateString();

        $query = Attendance::with(['employee.shift', 'employee.department'])
            ->join('employees', 'attendances.employee_id', '=', 'employees.id')
            ->where('employees.organization_id', $orgId)
            ->where('employees.active', 1)
            ->whereBetween('attendances.date', [$startDate, $endDate])
            ->select('attendances.*');

        if (!empty($ids))    $query->whereIn('attendances.employee_id', $ids);
        if ($departmentId)   $query->where('employees.department_id', $departmentId);

        if ($employeeType && $employeeType !== 'all') {
            $query->where('employees.employee_type', $employeeType);
        }

        $query->orderBy('attendances.date')->orderBy('employees.name');

        $records = $query->get();

        $interpreter = app(InterpretationService::class);
        return $interpreter->decorateCollection($records);
    }

    /**
     * PRESENT REPORT — only employees who clocked in/out in the period.
     */
    public function getPresent(
        int     $orgId,
        array   $ids = [],
        ?string $startDate = null,
        ?string $endDate = null,
        ?int    $departmentId = null,
        ?string $employeeType = null,
    ) {
        $startDate = Carbon::parse($startDate ?? now()->toDateString())->toDateString();
        $endDate   = Carbon::parse($endDate   ?? $startDate)->toDateString();

        $query = Attendance::with(['employee.shift', 'employee.department'])
            ->join('employees', 'attendances.employee_id', '=', 'employees.id')
            ->where('employees.organization_id', $orgId)
            ->where('employees.active', 1)
            ->whereBetween('attendances.date', [$startDate, $endDate])
            ->whereIn('attendances.status', ['clocked_in', 'clocked_out'])
            ->select('attendances.*');

        if (!empty($ids))  $query->whereIn('attendances.employee_id', $ids);
        if ($departmentId) $query->where('employees.department_id', $departmentId);

        if ($employeeType && $employeeType !== 'all') {
            $query->where('employees.employee_type', $employeeType);
        }


        $query->orderBy('attendances.date')->orderBy('attendances.check_in_time');

        $records = $query->get();
        $interpreter = app(InterpretationService::class);
        return $interpreter->decorateCollection($records);
    }

    /**
     * ABSENT REPORT — only absent / unchecked-in / leave records.
     */
    public function getAbsent(
        int     $orgId,
        array   $ids = [],
        ?string $startDate = null,
        ?string $endDate = null,
        ?int    $departmentId = null,
        ?string $employeeType = null,
    ) {
        $startDate = Carbon::parse($startDate ?? now()->toDateString())->toDateString();
        $endDate   = Carbon::parse($endDate   ?? $startDate)->toDateString();

        $query = Attendance::with(['employee.shift', 'employee.department'])
            ->join('employees', 'attendances.employee_id', '=', 'employees.id')
            ->where('employees.organization_id', $orgId)
            ->where('employees.active', 1)
            ->whereBetween('attendances.date', [$startDate, $endDate])
            ->whereIn('attendances.status', ['absent', 'unchecked_in', 'on_leave', 'sick_leave', 'sick_off'])
            ->select('attendances.*');

        if (!empty($ids))  $query->whereIn('attendances.employee_id', $ids);
        if ($departmentId) $query->where('employees.department_id', $departmentId);

        if ($employeeType && $employeeType !== 'all') {
            $query->where('employees.employee_type', $employeeType);
        }


        $query->orderBy('attendances.date')->orderBy('employees.name');

        $records = $query->get();
        $interpreter = app(InterpretationService::class);
        return $interpreter->decorateCollection($records);
    }

    /**
     * LATENESS REPORT — only records where employee was actually late
     * (is_late_checkin = 1 AND within_grace_period = 0).
     */
    public function getLateness(
        int     $orgId,
        array   $ids = [],
        ?string $startDate = null,
        ?string $endDate = null,
        ?int    $departmentId = null,
        ?string $employeeType = null,
    ) {
        $startDate = Carbon::parse($startDate ?? now()->toDateString())->toDateString();
        $endDate   = Carbon::parse($endDate   ?? $startDate)->toDateString();

        $query = Attendance::with(['employee.shift', 'employee.department'])
            ->join('employees', 'attendances.employee_id', '=', 'employees.id')
            ->where('employees.organization_id', $orgId)
            ->where('employees.active', 1)
            ->whereBetween('attendances.date', [$startDate, $endDate])
            ->where('attendances.is_late_checkin', 1)
            ->where('attendances.within_grace_period', 0)
            ->select('attendances.*');

        if (!empty($ids))  $query->whereIn('attendances.employee_id', $ids);
        if ($departmentId) $query->where('employees.department_id', $departmentId);

        if ($employeeType && $employeeType !== 'all') {
            $query->where('employees.employee_type', $employeeType);
        }

        $query->orderBy('attendances.date')->orderBy('attendances.minutes_late', 'desc');

        $records = $query->get();
        $interpreter = app(InterpretationService::class);
        return $interpreter->decorateCollection($records);
    }

    /**
     * WEEKLY HOURS REPORT — per employee.
     *
     * Returns a collection of objects:
     *   employee_id, employee, weeks[{ week_label, week_start, week_end, worked_hours, ot_hours }],
     *   total_worked_hours, total_ot_hours
     */
    public function getWeeklyHours(
        int     $orgId,
        array   $ids = [],
        ?string $startDate = null,
        ?string $endDate = null,
        ?int    $departmentId = null,
    ) {
        $startDate = Carbon::parse($startDate ?? now()->startOfMonth()->toDateString());
        $endDate   = Carbon::parse($endDate   ?? now()->toDateString());

        $query = $this->baseQuery($orgId, $ids, $startDate->toDateString(), $endDate->toDateString(), $departmentId)
            ->with(['employee', 'employee.department', 'employee.shift'])
            ->select(
                'attendances.employee_id',
                DB::raw("YEARWEEK(attendances.date, 1) as year_week"),
                DB::raw("MIN(attendances.date) as week_start"),
                DB::raw("MAX(attendances.date) as week_end"),
                DB::raw("SUM(attendances.worked_hours)   as worked_hours"),
                DB::raw("SUM(attendances.overtime_hours) as ot_hours")
            )
            ->groupBy('attendances.employee_id', DB::raw("YEARWEEK(attendances.date, 1)"))
            ->orderBy('attendances.employee_id')
            ->orderBy(DB::raw("YEARWEEK(attendances.date, 1)"));

        $rows = $query->get();

        // Group by employee
        $byEmployee = $rows->groupBy('employee_id');

        return $byEmployee->map(function ($employeeRows) {
            $first = $employeeRows->first();
            $weeks = $employeeRows->map(fn($r) => (object)[
                'year_week'    => $r->year_week,
                'week_start'   => $r->week_start,
                'week_end'     => $r->week_end,
                'week_label'   => Carbon::parse($r->week_start)->format('d M') . ' – ' . Carbon::parse($r->week_end)->format('d M'),
                'worked_hours' => round((float) $r->worked_hours, 2),
                'ot_hours'     => round((float) $r->ot_hours, 2),
            ])->values();

            return (object)[
                'employee_id'        => $first->employee_id,
                'employee'           => $first->employee,
                'weeks'              => $weeks,
                'total_worked_hours' => round($weeks->sum('worked_hours'), 2),
                'total_ot_hours'     => round($weeks->sum('ot_hours'), 2),
            ];
        })->values();
    }

    /**
     * WEEKLY HOURS BY DEPARTMENT — aggregated.
     */
    public function getWeeklyHoursByDepartment(
        int     $orgId,
        array   $ids = [],
        ?string $startDate = null,
        ?string $endDate = null,
    ) {
        $startDate = Carbon::parse($startDate ?? now()->startOfMonth()->toDateString());
        $endDate   = Carbon::parse($endDate   ?? now()->toDateString());

        $query = Attendance::query()
            ->join('employees',   'attendances.employee_id', '=', 'employees.id')
            ->join('departments', 'employees.department_id', '=', 'departments.id')
            ->where('employees.organization_id', $orgId)
            ->whereBetween('attendances.date', [$startDate->toDateString(), $endDate->toDateString()])
            ->select(
                'employees.department_id',
                'departments.name as department_name',
                DB::raw("YEARWEEK(attendances.date, 1) as year_week"),
                DB::raw("MIN(attendances.date) as week_start"),
                DB::raw("MAX(attendances.date) as week_end"),
                DB::raw("COUNT(DISTINCT employees.id) as employee_count"),
                DB::raw("SUM(attendances.worked_hours)   as worked_hours"),
                DB::raw("SUM(attendances.overtime_hours) as ot_hours")
            )
            ->groupBy('employees.department_id', 'departments.name', DB::raw("YEARWEEK(attendances.date, 1)"))
            ->orderBy('employees.department_id')
            ->orderBy(DB::raw("YEARWEEK(attendances.date, 1)"));

        if (!empty($ids)) $query->whereIn('employees.id', $ids);

        $rows = $query->get();

        return $rows->groupBy('department_id')->map(function ($deptRows) {
            $first = $deptRows->first();
            $weeks = $deptRows->map(fn($r) => (object)[
                'year_week'      => $r->year_week,
                'week_start'     => $r->week_start,
                'week_end'       => $r->week_end,
                'week_label'     => Carbon::parse($r->week_start)->format('d M') . ' – ' . Carbon::parse($r->week_end)->format('d M'),
                'employee_count' => $r->employee_count,
                'worked_hours'   => round((float) $r->worked_hours, 2),
                'ot_hours'       => round((float) $r->ot_hours, 2),
            ])->values();

            return (object)[
                'department_id'      => $first->department_id,
                'department_name'    => $first->department_name,
                'weeks'              => $weeks,
                'total_worked_hours' => round($weeks->sum('worked_hours'), 2),
                'total_ot_hours'     => round($weeks->sum('ot_hours'), 2),
            ];
        })->values();
    }
}
