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

        // One record per employee per day
        $query->select(
            'attendances.employee_id',
            'attendances.date',
            DB::raw("
            MAX(CASE WHEN attendances.status IN ('clocked_in','clocked_out')
                OR attendances.check_in_time IS NOT NULL THEN 1 ELSE 0 END) as present_day
        "),
            DB::raw("
            MAX(CASE WHEN attendances.status IN ('absent','unchecked_in') THEN 1 ELSE 0 END) as absent_day
        "),
            DB::raw("
            MAX(CASE WHEN attendances.status = 'on_leave' THEN 1 ELSE 0 END) as leave_day
        "),
            DB::raw("
            MAX(CASE WHEN attendances.status IN ('sick_leave','sick_off') THEN 1 ELSE 0 END) as sick_day
        "),
            DB::raw("
            MAX(CASE WHEN attendances.status = 'off_shift' THEN 1 ELSE 0 END) as off_shift_day
        "),
            DB::raw("SUM(attendances.worked_hours) as worked_hours_day"),
            DB::raw("SUM(attendances.overtime_hours) as ot_hours_day")
        )->groupBy('attendances.employee_id', 'attendances.date');

        $dailyRecords = $query->get();

        // Aggregate per employee for the date range
        return $dailyRecords->groupBy('employee_id')->map(function ($days, $employeeId) {
            return (object)[
                'employee_id' => $employeeId,
                'present_days' => $days->sum('present_day'),
                'absent_days' => $days->sum('absent_day'),
                'leave_days' => $days->sum('leave_day'),
                'sick_days' => $days->sum('sick_day'),
                'off_shift_days' => $days->sum('off_shift_day'),
                'total_days' => $days->count(),
                'total_worked_hours' => $days->sum('worked_hours_day'),
                'total_ot_hours' => $days->sum('ot_hours_day'),
                'employee' => $days->first()?->employee,
            ];
        })->values();
    }


    public function getByDepartment($orgId, $ids, $start_date, $end_date)
    {
        $orgId = auth()->user()->employee->organization_id ?? $orgId;
        $ids = $ids ?? [];

        // Step 1: get daily attendance per employee
        $dailyQuery = Attendance::query()
            ->join('employees', 'attendances.employee_id', '=', 'employees.id')
            ->join('departments', 'employees.department_id', '=', 'departments.id')
            ->where('employees.organization_id', $orgId);

        if ($start_date) $dailyQuery->where('attendances.date', '>=', $start_date);
        if ($end_date) $dailyQuery->where('attendances.date', '<=', $end_date);
        if (!empty($ids)) $dailyQuery->whereIn('employees.id', $ids);

        // Aggregate per employee per day
        $dailyQuery->select(
            'employees.department_id',
            'departments.name as department_name',
            'attendances.employee_id',
            DB::raw("MAX(CASE WHEN attendances.status IN ('clocked_in','clocked_out') THEN 1 ELSE 0 END) as present_day"),
            DB::raw("MAX(CASE WHEN attendances.status IN ('absent','unchecked_in') THEN 1 ELSE 0 END) as absent_day"),
            DB::raw("MAX(CASE WHEN attendances.status = 'on_leave' THEN 1 ELSE 0 END) as leave_day"),
            DB::raw("MAX(CASE WHEN attendances.status IN ('sick_leave','sick_off') THEN 1 ELSE 0 END) as sick_day"),
            DB::raw("MAX(CASE WHEN attendances.status = 'off_shift' THEN 1 ELSE 0 END) as off_shift_day"),
            DB::raw("SUM(attendances.worked_hours) as worked_hours_day"),
            DB::raw("SUM(attendances.overtime_hours) as ot_hours_day")
        )
            ->groupBy('attendances.employee_id', 'attendances.date', 'employees.department_id', 'departments.name');

        $dailyRecords = $dailyQuery->get();

        // Step 2: Aggregate per department
        return $dailyRecords->groupBy('department_id')->map(function ($records, $departmentId) {
            $first = $records->first();
            return [
                'department_id' => $departmentId,
                'department_name' => $first->department_name,
                'employee_count' => $records->groupBy('employee_id')->count(),
                'present_days' => $records->sum('present_day'),
                'absent_days' => $records->sum('absent_day'),
                'leave_days' => $records->sum('leave_day'),
                'sick_days' => $records->sum('sick_day'),
                'off_shift_days' => $records->sum('off_shift_day'),
                'total_days' => $records->count(),
                'total_worked_hours' => $records->sum('worked_hours_day'),
                'total_ot_hours' => $records->sum('ot_hours_day'),
            ];
        })->values();
    }

}
