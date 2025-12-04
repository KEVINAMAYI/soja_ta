<?php

namespace App\Services;

use App\Models\Attendance;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceReportService
{
    public function getDaily(
        int     $orgId,
        array   $ids = [],
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $status = null,
    )
    {
        $startDate = $startDate ?? now()->toDateString();
        $endDate = $endDate ?? $startDate;

        $startDate = Carbon::parse($startDate)->toDateString();
        $endDate = Carbon::parse($endDate)->toDateString();

        $query = Attendance::with(['employee.shift'])
            ->whereHas('employee', fn($q) => $q->where('organization_id', $orgId))
            ->whereBetween('date', [$startDate, $endDate]);

        if (!empty($ids)) {
            $query->whereIn('id', $ids);
        }

        // Filter by status
        if ($status) {
            if ($status === 'absent') {
                $query->whereIn('status', ['absent', 'unchecked_in']);
            } elseif ($status === 'present') {
                $query->whereIn('status', ['clocked_in', 'clocked_out']);
            } else {
                $query->where('status', $status);
            }
        }

        // Ensure only one record per employee per day
        $query->orderBy('date')
            ->orderBy('check_in_time'); // pick earliest check-in per day

        $records = $query->get();

        // Unique per employee per date
        return $records->unique(function ($item) {
            return $item->employee_id . '_' . $item->date;
        })->values();
    }


    public function baseQuery($orgId, $ids, $start_date, $end_date, $department_id)
    {
        $query = Attendance::query()
            ->join('employees', 'attendances.employee_id', '=', 'employees.id')
            ->where('employees.organization_id', $orgId);

        // Filter by department
        if ($department_id && $department_id !== 'all') {
            $query->where('employees.department_id', $department_id);
        }

        // Date filtering
        if ($start_date) {
            $query->where('attendances.date', '>=', $start_date);
        }

        if ($end_date) {
            $query->where('attendances.date', '<=', $end_date);
        }

        if (!empty($ids)) {
            $query->whereIn('attendances.employee_id', $ids);
        }

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

        return $query->get()->map(function ($record) {
            return (object)[
                'employee_id' => $record->employee_id,
                'present_days' => $record->present_days,
                'absent_days' => $record->absent_days,
                'leave_days' => $record->leave_days,
                'sick_days' => $record->sick_days,
                'off_shift_days' => $record->off_shift_days,
                'total_days' => $record->total_days,
                'total_worked_hours' => $record->total_worked_hours,
                'total_ot_hours' => $record->total_ot_hours,
                'employee' => $record->employee,
            ];
        })->values();
    }


    public function getByDepartment($orgId, $ids, $start_date, $end_date)
    {
        $orgId = auth()->user()->employee->organization_id ?? $orgId;
        $ids = $ids ?? [];

        $query = Attendance::query()
            ->join('employees', 'attendances.employee_id', '=', 'employees.id')
            ->join('departments', 'employees.department_id', '=', 'departments.id')
            ->where('employees.organization_id', $orgId);

        if ($start_date) $query->where('attendances.date', '>=', $start_date);
        if ($end_date) $query->where('attendances.date', '<=', $end_date);
        if (!empty($ids)) $query->whereIn('employees.id', $ids);

        $query->select(
            'employees.department_id',
            'departments.name as department_name',

            // Count unique employees in department
            DB::raw("COUNT(DISTINCT employees.id) as employee_count"),

            // Total unique employee-day combinations
            DB::raw("COUNT(DISTINCT CONCAT(employees.id, '-', attendances.date)) as total_days"),

            // Present Days
            DB::raw("COUNT(DISTINCT CASE WHEN attendances.status IN ('clocked_in','clocked_out') THEN CONCAT(employees.id, '-', attendances.date) END) as present_days"),

            // Absent Days
            DB::raw("COUNT(DISTINCT CASE WHEN attendances.status IN ('absent','unchecked_in') THEN CONCAT(employees.id, '-', attendances.date) END) as absent_days"),

            // Leave Days
            DB::raw("COUNT(DISTINCT CASE WHEN attendances.status = 'on_leave' THEN CONCAT(employees.id, '-', attendances.date) END) as leave_days"),

            // Sick Days
            DB::raw("COUNT(DISTINCT CASE WHEN attendances.status IN ('sick_leave','sick_off') THEN CONCAT(employees.id, '-', attendances.date) END) as sick_days"),

            // Off Shift Days
            DB::raw("COUNT(DISTINCT CASE WHEN attendances.status = 'off_shift' THEN CONCAT(employees.id, '-', attendances.date) END) as off_shift_days"),

            // Hours
            DB::raw("SUM(attendances.worked_hours) as total_worked_hours"),
            DB::raw("SUM(attendances.overtime_hours) as total_ot_hours")
        )
            ->groupBy('employees.department_id', 'departments.name');

        return $query->get()->map(function ($record) {
            return (object)[
                'department_id' => $record->department_id,
                'department_name' => $record->department_name,
                'employee_count' => $record->employee_count,
                'present_days' => $record->present_days,
                'absent_days' => $record->absent_days,
                'leave_days' => $record->leave_days,
                'sick_days' => $record->sick_days,
                'off_shift_days' => $record->off_shift_days,
                'total_days' => $record->total_days,
                'total_worked_hours' => $record->total_worked_hours,
                'total_ot_hours' => $record->total_ot_hours,
            ];
        })->values();
    }
}
